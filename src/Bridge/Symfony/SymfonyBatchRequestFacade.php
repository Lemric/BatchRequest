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
use Lemric\BatchRequest\Handler\{BatchRequestHandler,
    FiberExecutionStrategy,
    ProcessBatchRequestCommand,
    TransactionExecutorInterface};
use Lemric\BatchRequest\Parser\JsonBatchRequestParser;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use Psr\Log\{LoggerInterface, NullLogger};
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;
use function preg_replace;
use function strlen;
use function substr;

/**
 * Symfony facade for batch request processing.
 */
final readonly class SymfonyBatchRequestFacade
{
    private const TRACE_MAX_LENGTH = 4096;

    private const SECRET_REDACT_REGEX = '/(authorization|password|token|secret|api[_-]?key)\s*[:=]\s*\S+/i';

    private BatchRequestHandler $handler;

    private JsonBatchRequestParser $parser;

    /**
     * @param array<int, string> $forwardedHeadersWhitelist Lower-case parent-request headers safe to propagate to sub-requests.
     */
    public function __construct(
        private HttpKernelInterface $httpKernel,
        private ?RateLimiterFactory $rateLimiterFactory = null,
        private ?LoggerInterface $logger = null,
        private int $maxBatchSize = 50,
        int $maxConcurrency = 8,
        int $maxTransactionContentLength = 262144,
        array $forwardedHeadersWhitelist = [],
        ?TransactionExecutorInterface $transactionExecutor = null,
    ) {
        $executor = $transactionExecutor ?? new SymfonyTransactionExecutor($this->httpKernel);
        $transactionValidator = new TransactionValidator();
        $validator = new BatchRequestValidator(
            $transactionValidator,
            $this->maxBatchSize,
            $maxTransactionContentLength,
        );

        $this->handler = new BatchRequestHandler(
            $executor,
            $validator,
            $this->logger ?? new NullLogger(),
            new FiberExecutionStrategy(),
            $maxConcurrency,
        );

        $this->parser = new JsonBatchRequestParser(
            maxTransactionContentLength: $maxTransactionContentLength,
            forwardedHeadersWhitelist: $forwardedHeadersWhitelist,
        );
    }

    /**
     * Handles a Symfony HTTP request containing a batch of operations.
     */
    public function handle(Request $request): Response
    {
        try {
            $context = $this->extractContext($request);
            $batchRequest = $this->parser->parse($request->getContent(), $context);

            $this->checkRateLimit($request, $batchRequest->count());

            $command = new ProcessBatchRequestCommand($batchRequest);
            $batchResponse = $this->handler->handle($command);

            return $this->createJsonResponse($batchResponse->toArray());
        } catch (RateLimitException) {
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
     * Checks rate limit using the already-parsed transaction count
     * (single-pass: no second JSON decode). Token consumption is
     * proportional to batch size.
     */
    private function checkRateLimit(Request $request, int $transactionCount): void
    {
        if (null === $this->rateLimiterFactory) {
            return;
        }

        $limiter = $this->rateLimiterFactory->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(max(1, $transactionCount));

        if (!$limit->isAccepted()) {
            throw new RateLimitException('Rate limit exceeded', $limit->getRetryAfter()->getTimestamp());
        }
    }

    /**
     * Creates an error response in the expected format.
     */
    private function createErrorResponse(string $message, int $status, string $type): JsonResponse
    {
        $response = new JsonResponse(
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
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    /**
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
        $logger = $this->logger ?? new NullLogger();

        $trace = $e->getTraceAsString();
        if (strlen($trace) > self::TRACE_MAX_LENGTH) {
            $trace = substr($trace, 0, self::TRACE_MAX_LENGTH).'…[truncated]';
        }
        $trace = (string) preg_replace(self::SECRET_REDACT_REGEX, '$1=***', $trace);

        $logger->error('Batch request processing failed', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $trace,
        ]);
    }
}
