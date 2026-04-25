<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */
declare(strict_types=1);

namespace Lemric\BatchRequest\Handler;

use Fiber;
use Generator;
use Lemric\BatchRequest\{BatchResponse, BatchResponseInterface, TransactionInterface};
use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\Validator\ValidatorInterface;
use Psr\Log\{LoggerInterface, NullLogger};
use Throwable;
use function ksort;

/**
 * Handles batch request processing with validation and execution.
 */
final readonly class BatchRequestHandler implements BatchRequestHandlerInterface
{
    private const DEFAULT_MAX_CONCURRENCY = 8;

    private LoggerInterface $effectiveLogger;

    public function __construct(
        private TransactionExecutorInterface $executor,
        private ValidatorInterface $validator,
        private ?LoggerInterface $logger = null,
        private ?ExecutionStrategyInterface $strategy = null,
        private int $maxConcurrency = self::DEFAULT_MAX_CONCURRENCY,
    ) {
        $this->effectiveLogger = $this->logger ?? new NullLogger();
    }

    public function handle(ProcessBatchRequestCommandInterface $command): BatchResponseInterface
    {
        $batchRequest = $command->getBatchRequest();
        $count = $batchRequest->count();

        $this->effectiveLogger->info('Processing batch request', [
            'transaction_count' => $count,
            'client_identifier' => $batchRequest->getClientIdentifier(),
        ]);

        try {
            $this->validator->validate($batchRequest);
        } catch (ValidationException $e) {
            $this->effectiveLogger->error('Batch validation failed', [
                'error' => $e->getMessage(),
                'violations' => $e->getViolations(),
            ]);

            $responses = [];
            for ($i = 0; $i < $count; ++$i) {
                $responses[] = [
                    'code' => 500,
                    'body' => [
                        'error' => [
                            'type' => 'MethodNotAllowedHttpException',
                            'message' => 'Method Not Allowed: '.$e->getMessage(),
                        ],
                    ],
                ];
            }

            return new BatchResponse($responses);
        }

        $transactions = $batchRequest->getTransactions();
        $includeHeaders = $batchRequest->shouldIncludeHeaders();

        $responses = iterator_to_array($this->executeAll($transactions, $includeHeaders), true);
        ksort($responses);
        $responses = array_values($responses);

        $batchResponse = new BatchResponse($responses);

        $this->effectiveLogger->info('Batch request completed', [
            'total' => $count,
            'failures' => $batchResponse->getFailureCount(),
            'successful' => $batchResponse->isSuccessful(),
        ]);

        return $batchResponse;
    }

    /**
     * Yields `[index => response]` for each transaction. When a strategy
     * is configured and a contiguous read-only block can be parallelised,
     * uses Fibers with bounded concurrency; write operations are always
     * serialised to preserve causal ordering and avoid container-state
     * corruption (EM/PDO/session) shared across the framework kernel.
     *
     * @param array<int, TransactionInterface> $transactions
     *
     * @return Generator<int, array{code: int, body: mixed, headers?: array<string, string>}>
     */
    private function executeAll(array $transactions, bool $includeHeaders): Generator
    {
        if (null === $this->strategy || $this->maxConcurrency < 2) {
            foreach ($transactions as $index => $transaction) {
                yield $index => $this->executeOne($transaction, $index, $includeHeaders);
            }

            return;
        }

        foreach ($this->strategy->groupTransactions($transactions) as $group) {
            if (1 === count($group) || !$this->strategy->canExecuteInParallel($group)) {
                foreach ($group as $index => $transaction) {
                    yield $index => $this->executeOne($transaction, $index, $includeHeaders);
                }

                continue;
            }

            yield from $this->executeParallel($group, $includeHeaders);
        }
    }

    /**
     * Runs a contiguous read-only group via PHP Fibers with a bounded
     * pool of `maxConcurrency` cooperating fibers. Each fiber suspends
     * once after start, allowing the scheduler to interleave I/O.
     *
     * @param array<int, TransactionInterface> $group Indexes preserved.
     *
     * @return Generator<int, array{code: int, body: mixed, headers?: array<string, string>}>
     */
    private function executeParallel(array $group, bool $includeHeaders): Generator
    {
        $pending = $group;
        $active = [];
        $results = [];
        $limit = $this->maxConcurrency;

        while ([] !== $pending || [] !== $active) {
            while ([] !== $pending && count($active) < $limit) {
                $index = array_key_first($pending);
                $transaction = $pending[$index];
                unset($pending[$index]);

                $fiber = new Fiber(function () use ($transaction, $index, $includeHeaders): array {
                    Fiber::suspend();

                    return $this->executeOne($transaction, $index, $includeHeaders);
                });

                try {
                    $fiber->start();
                } catch (Throwable $e) {
                    $results[$index] = $this->makeErrorResponse($e, $index, $transaction);

                    continue;
                }

                $active[$index] = $fiber;
            }

            foreach ($active as $index => $fiber) {
                try {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                } catch (Throwable $e) {
                    $results[$index] = $this->makeErrorResponse($e, $index, $group[$index]);
                    unset($active[$index]);

                    continue;
                }

                if ($fiber->isTerminated()) {
                    try {
                        $results[$index] = $fiber->getReturn();
                    } catch (Throwable $e) {
                        $results[$index] = $this->makeErrorResponse($e, $index, $group[$index]);
                    }
                    unset($active[$index]);
                }
            }
        }

        foreach ($results as $index => $response) {
            yield $index => $response;
        }
    }

    /**
     * @return array{code: int, body: mixed, headers?: array<string, string>}
     */
    private function executeOne(TransactionInterface $transaction, int $index, bool $includeHeaders): array
    {
        try {
            $response = $this->executor->execute($transaction);

            if (!$includeHeaders) {
                unset($response['headers']);
            }

            $this->effectiveLogger->debug('Transaction executed successfully', [
                'index' => $index,
                'method' => $transaction->getMethod(),
                'uri' => $transaction->getUri(),
                'status' => $response['code'],
            ]);

            return $response;
        } catch (Throwable $e) {
            return $this->makeErrorResponse($e, $index, $transaction);
        }
    }

    /**
     * @return array{code: int, body: array{error: array{type: string, message: string}}}
     */
    private function makeErrorResponse(Throwable $e, int $index, TransactionInterface $transaction): array
    {
        $this->effectiveLogger->error('Transaction execution failed', [
            'index' => $index,
            'method' => $transaction->getMethod(),
            'uri' => $transaction->getUri(),
            'error' => $e->getMessage(),
        ]);

        return [
            'code' => 500,
            'body' => [
                'error' => [
                    'type' => 'ExecutionException',
                    'message' => $e->getMessage(),
                ],
            ],
        ];
    }
}

