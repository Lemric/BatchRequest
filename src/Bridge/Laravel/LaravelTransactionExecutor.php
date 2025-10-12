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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\TransactionInterface;
use Throwable;

/**
 * Executes transactions using Laravel's application kernel.
 */
final readonly class LaravelTransactionExecutor implements TransactionExecutorInterface
{
    public function __construct(
        private Kernel $kernel,
    ) {
    }

    /**
     * @return array{code: int, body: mixed, headers: array<string, string>}
     */
    public function execute(TransactionInterface $transaction): array
    {
        try {
            $request = $this->createRequest($transaction);
            $response = $this->kernel->handle($request);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return [
                'code' => 500,
                'body' => [
                    'error' => [
                        'type' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ],
                'headers' => [],
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

    /**
     * Extracts headers from Laravel Response.
     *
     * @return array<string, string>
     */
    private function extractHeaders(Response $response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            if (is_array($values)) {
                $headers[$name] = (string) end($values);
            } else {
                $headers[$name] = (string) $values;
            }
        }
        return $headers;
    }

    /**
     * Formats Laravel Response into batch response format.
     *
     * @return array{code: int, body: mixed, headers: array<string, string>}
     */
    private function formatResponse(Response $response): array
    {
        $content = $response->getContent();
        $body = false === $content ? [] : $content;

        $contentType = $response->headers->get('Content-Type', '');
        if ($contentType !== null && str_contains($contentType, 'application/json')) {
            try {
                $bodyString = is_string($body) ? $body : '';
                $decoded = json_decode($bodyString, true, 512, JSON_THROW_ON_ERROR);
                $body = is_array($decoded) ? $decoded : $body;
            } catch (Throwable) {
                // Keep original body if JSON decode fails
            }
        }

        return [
            'code' => $response->getStatusCode(),
            'body' => $body,
            'headers' => $this->extractHeaders($response),
        ];
    }
}

