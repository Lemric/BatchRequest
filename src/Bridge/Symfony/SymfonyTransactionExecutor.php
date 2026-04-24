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

namespace Lemric\BatchRequest\Bridge\Symfony;

use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\TransactionInterface;
use ReflectionClass;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;
use function is_array;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Executes transactions using Symfony HttpKernel.
 */
final readonly class SymfonyTransactionExecutor implements TransactionExecutorInterface
{
    public function __construct(
        private HttpKernelInterface $httpKernel,
    ) {
    }

    public function execute(TransactionInterface $transaction): array
    {
        $request = $this->createSymfonyRequest($transaction);

        try {
            $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);

            return $this->formatResponse($response);
        } catch (HttpExceptionInterface $e) {
            // HTTP exceptions (e.g. NotFound, MethodNotAllowed) — their short class
            // name and message are safe and informative for API clients.
            return $this->createErrorResponse($e->getStatusCode(), (new ReflectionClass($e))->getShortName(), $e->getMessage());
        } catch (Throwable $e) {
            return $this->createErrorResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'ExecutionException', 'Internal server error');
        }
    }

    /**
     * Creates an error response from an exception.
     *
     * @return array{code: int, body: array{error: array{type: string, message: string}}}
     */
    private function createErrorResponse(int $statusCode, string $type, string $message): array
    {
        return [
            'code' => $statusCode,
            'body' => [
                'error' => [
                    'type' => $type,
                    'message' => $message,
                ],
            ],
        ];
    }

    /**
     * Creates a Symfony Request from a Transaction.
     */
    private function createSymfonyRequest(TransactionInterface $transaction): Request
    {
        $request = Request::create(
            uri: $transaction->getUri(),
            method: $transaction->getMethod(),
            parameters: $transaction->getParameters(),
            cookies: $transaction->getCookies(),
            files: $transaction->getFiles(),
            server: $transaction->getServerVariables(),
            content: $transaction->getContent(),
        );

        $request->headers->replace($transaction->getHeaders());

        return $request;
    }

    /**
     * Extracts headers from Symfony Response.
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
     * Formats Symfony Response into batch response format.
     *
     * @return array{code: int, body: mixed, headers: array<string, string>}
     */
    private function formatResponse(Response $response): array
    {
        $content = $response->getContent();
        $body = false === $content ? [] : $content;

        $contentType = $response->headers->get('Content-Type', '');
        if (null !== $contentType && str_contains($contentType, 'application/json')) {
            try {
                $bodyString = is_string($body) ? $body : '';
                $decoded = json_decode($bodyString, true, 512, JSON_THROW_ON_ERROR);
                $body = is_array($decoded) ? $decoded : $body;
            } catch (Throwable) {
                // Keep original body if JSON decode fails
            }
        }

        return [
            'code' => $response->getStatusCode() ?: Response::HTTP_OK,
            'body' => $body,
            'headers' => $this->extractHeaders($response),
        ];
    }
}
