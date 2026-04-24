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

use Lemric\BatchRequest\{BatchResponse, BatchResponseInterface};
use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\Validator\ValidatorInterface;
use Psr\Log\{LoggerInterface, NullLogger};
use Throwable;

/**
 * Handles batch request processing with validation and execution.
 */
final readonly class BatchRequestHandler implements BatchRequestHandlerInterface
{
    public function __construct(
        private TransactionExecutorInterface $executor,
        private ValidatorInterface $validator,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(ProcessBatchRequestCommandInterface $command): BatchResponseInterface
    {
        $batchRequest = $command->getBatchRequest();
        $effectiveLogger = $this->logger ?? new NullLogger();

        $effectiveLogger->info('Processing batch request', [
            'transaction_count' => $batchRequest->count(),
            'client_identifier' => $batchRequest->getClientIdentifier(),
        ]);

        try {
            $this->validator->validate($batchRequest);
        } catch (ValidationException $e) {
            $effectiveLogger->error('Batch validation failed', [
                'error' => $e->getMessage(),
                'violations' => $e->getViolations(),
            ]);

            $responses = [];
            foreach ($batchRequest->getTransactions() as $index => $transaction) {
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

        $responses = [];
        foreach ($batchRequest->getTransactions() as $index => $transaction) {
            try {
                $response = $this->executor->execute($transaction);

                if (!$batchRequest->shouldIncludeHeaders()) {
                    unset($response['headers']);
                }

                $responses[] = $response;

                $effectiveLogger->debug('Transaction executed successfully', [
                    'index' => $index,
                    'method' => $transaction->getMethod(),
                    'uri' => $transaction->getUri(),
                    'status' => $response['code'],
                ]);
            } catch (Throwable $e) {
                $effectiveLogger->error('Transaction execution failed', [
                    'index' => $index,
                    'method' => $transaction->getMethod(),
                    'uri' => $transaction->getUri(),
                    'error' => $e->getMessage(),
                ]);

                $responses[] = [
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

        $batchResponse = new BatchResponse($responses);

        $effectiveLogger->info('Batch request completed', [
            'total' => $batchRequest->count(),
            'failures' => $batchResponse->getFailureCount(),
            'successful' => $batchResponse->isSuccessful(),
        ]);

        return $batchResponse;
    }
}
