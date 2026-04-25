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

use Lemric\BatchRequest\Bridge\ResponseFormatter;
use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\TransactionInterface;
use ReflectionClass;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Executes transactions using Symfony HttpKernel.
 */
final readonly class SymfonyTransactionExecutor implements TransactionExecutorInterface
{
    private ResponseFormatter $formatter;

    public function __construct(
        private HttpKernelInterface $httpKernel,
    ) {
        $this->formatter = new ResponseFormatter();
    }

    public function execute(TransactionInterface $transaction): array
    {
        $request = $this->createSymfonyRequest($transaction);

        try {
            $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);

            return $this->formatter->format($response);
        } catch (HttpExceptionInterface $e) {
            // HTTP exceptions (e.g. NotFound, MethodNotAllowed) — their short class
            // name and message are safe and informative for API clients.
            return $this->createErrorResponse($e->getStatusCode(), new ReflectionClass($e)->getShortName(), $e->getMessage());
        } catch (Throwable $e) {
            return $this->createErrorResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'ExecutionException', 'Internal server error');
        }
    }

    /**
     * Creates an error response from an exception.
     *
     * Errors are emitted as RFC 7807 problem documents
     * (Content-Type: application/problem+json) so clients can dispatch on
     * media type. The legacy `body.error.{type,message}` envelope is kept
     * for backward compatibility.
     *
     * @return array{code: int, body: array{error: array{type: string, message: string}}, headers: array<string, string>}
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
            'headers' => [
                'Content-Type' => 'application/problem+json',
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
}
