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

namespace Lemric\BatchRequest\Bridge\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\{Request};
use Lemric\BatchRequest\Bridge\ResponseFormatter;
use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\TransactionInterface;
use Throwable;

/**
 * Executes transactions using Laravel's application kernel.
 */
final readonly class LaravelTransactionExecutor implements TransactionExecutorInterface
{
    private ResponseFormatter $formatter;

    public function __construct(
        private Kernel $kernel,
    ) {
        $this->formatter = new ResponseFormatter();
    }

    /**
     * @return array{code: int, body: mixed, headers: array<string, string>}
     */
    public function execute(TransactionInterface $transaction): array
    {
        try {
            $request = $this->createRequest($transaction);
            $response = $this->kernel->handle($request);

            return $this->formatter->format($response);
        } catch (Throwable $e) {
            return [
                'code' => 500,
                'body' => [
                    'error' => [
                        'type' => 'ExecutionException',
                        'message' => 'Internal server error',
                    ],
                ],
                // RFC 7807: synthesized error sub-responses are problem documents.
                'headers' => [
                    'Content-Type' => 'application/problem+json',
                ],
            ];
        }
    }

    /**
     * Creates a Laravel Request from a Transaction.
     */
    private function createRequest(TransactionInterface $transaction): Request
    {
        $request = Request::create(
            $transaction->getUri(),
            $transaction->getMethod(),
            $transaction->getParameters(),
            $transaction->getCookies(),
            $transaction->getFiles(),
            $transaction->getServerVariables(),
            $transaction->getContent(),
        );

        foreach ($transaction->getHeaders() as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
