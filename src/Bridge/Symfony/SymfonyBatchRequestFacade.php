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

use Lemric\BatchRequest\Exception\{RateLimitException};
use Lemric\BatchRequest\Handler\{BatchRequestHandler, ProcessBatchRequestCommand};
use Lemric\BatchRequest\Parser\JsonBatchRequestParser;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use Psr\Log\{LoggerInterface, NullLogger};
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;
use const JSON_THROW_ON_ERROR;

/**
 * Symfony facade for batch request processing.
 *
 * This class provides backward compatibility with the original API
 * while using the new architecture internally.
 */
final readonly class SymfonyBatchRequestFacade
{
    private BatchRequestHandler $handler;

    private JsonBatchRequestParser $parser;

    public function __construct(
        private HttpKernelInterface $httpKernel,
        private ?RateLimiterFactory $rateLimiterFactory = null,
        private ?LoggerInterface $logger = null,
        private int $maxBatchSize = 50,
    ) {
        $executor = new SymfonyTransactionExecutor($this->httpKernel);
        $transactionValidator = new TransactionValidator();
        $validator = new BatchRequestValidator($transactionValidator, $this->maxBatchSize);

        $this->handler = new BatchRequestHandler(
            $executor,
            $validator,
            $this->logger ?? new NullLogger(),
        );

        $this->parser = new JsonBatchRequestParser();
    }

    /**
     * Handles a Symfony HTTP request containing a batch of operations.
     */
    public function handle(Request $request): Response
    {
        try {
            $this->checkRateLimit($request);

            $context = $this->extractContext($request);
            $batchRequest = $this->parser->parse($request->getContent(), $context);

            $command = new ProcessBatchRequestCommand($batchRequest);
            $batchResponse = $this->handler->handle($command);

            return $this->createJsonResponse($batchResponse->toArray());
        } catch (RateLimitException $e) {
            return $this->createErrorResponse(
                'Too many requests',
                Response::HTTP_TOO_MANY_REQUESTS,
                'rate_limit_error',
            );
        } catch (Throwable $e) {
            $this->logError($e);

            return $this->createErrorResponse(
                'Internal server error',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'system_error',
            );
        }
    }

    /**
     * Checks rate limit if RateLimiterFactory is configured.
     */
    private function checkRateLimit(Request $request): void
    {
        if (null === $this->rateLimiterFactory) {
            return;
        }

        $limiter = $this->rateLimiterFactory->create($request->getClientIp() ?? 'unknown');

        $transactionCount = 1;
        try {
            $decoded = json_decode(
                $request->getContent(),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (is_array($decoded)) {
                $transactionCount = max(1, count($decoded));
            }
        } catch (Throwable) {
            // keep default of 1
        }

        $limit = $limiter->consume($transactionCount);

        if (!$limit->isAccepted()) {
            throw new RateLimitException('Rate limit exceeded', $limit->getRetryAfter()->getTimestamp());
        }
    }

    /**
     * Creates an error response in the expected format.
     */
    private function createErrorResponse(string $message, int $status, string $type): JsonResponse
    {
        return new JsonResponse(
            [
                'result' => 'error',
                'errors' => [
                    [
                        'message' => $message,
                        'type' => $type,
                    ],
                ],
            ],
            $status,
        );
    }

    /**
     * Creates a JSON response from batch response data.
     *
     * @param array<int, array{code: int, body: mixed, headers?: array<string, string>}> $data
     */
    private function createJsonResponse(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Extracts context from Symfony Request.
     *
     * @return array<string, mixed>
     */
    private function extractContext(Request $request): array
    {
        return [
            'include_headers' => $request->query->getBoolean('include_headers')
                || $request->request->getBoolean('include_headers'),
            'client_identifier' => $request->getClientIp() ?? 'unknown',
            'headers' => $request->headers->all(),
            'cookies' => $request->cookies->all(),
            'files' => $request->files->all(),
            'server' => $request->server->all(),
        ];
    }

    private function logError(Throwable $e): void
    {
        ($this->logger ?? new NullLogger())->error('Batch request processing failed', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
