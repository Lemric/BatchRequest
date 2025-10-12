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
            return $this->createErrorResponse($e, $e->getStatusCode());
        } catch (Throwable $e) {
            return $this->createErrorResponse($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Creates an error response from an exception.
     *
     * @return array{code: int, body: array{error: array{type: string, message: string}}}
     */
    private function createErrorResponse(Throwable $e, int $statusCode): array
    {
        $reflection = new ReflectionClass($e);

        return [
            'code' => $statusCode,
            'body' => [
                'error' => [
                    'type' => $reflection->getShortName(),
                    'message' => $e->getMessage(),
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
        return array_map(function ($values) {
            return is_array($values) ? end($values) : $values;
        }, $response->headers->all());
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
        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
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
