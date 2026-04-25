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
use Illuminate\Http\{JsonResponse, Request};
use Lemric\BatchRequest\Exception\{RateLimitException, ValidationException};
use Lemric\BatchRequest\Handler\{BatchRequestHandler, FiberExecutionStrategy, ProcessBatchRequestCommand};
use Lemric\BatchRequest\Parser\JsonBatchRequestParser;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use Psr\Log\{LoggerInterface, NullLogger};
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function preg_replace;
use function strlen;
use function substr;

/**
 * Laravel facade for batch request processing.
 */
final readonly class LaravelBatchRequestFacade
{
    private const TRACE_MAX_LENGTH = 4096;

    private const SECRET_REDACT_REGEX = '/(authorization|password|token|secret|api[_-]?key)\s*[:=]\s*\S+/i';

    private BatchRequestHandler $handler;

    private JsonBatchRequestParser $parser;

    /**
     * @param array<int, string> $forwardedHeadersWhitelist Lower-case parent-request headers safe to propagate to sub-requests.
     */
    public function __construct(
        private Kernel $kernel,
        private ?LoggerInterface $logger = null,
        private int $maxBatchSize = 50,
        int $maxConcurrency = 8,
        int $maxTransactionContentLength = 262144,
        array $forwardedHeadersWhitelist = [],
    ) {
        $executor = new LaravelTransactionExecutor($this->kernel);
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
     * Handles a Laravel HTTP request containing a batch of operations.
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $context = $this->extractContext($request);
            $batchRequest = $this->parser->parse($request->getContent(), $context);

            $command = new ProcessBatchRequestCommand($batchRequest);
            $batchResponse = $this->handler->handle($command);

            return new JsonResponse($batchResponse->toArray());
        } catch (RateLimitException) {
            return $this->createErrorResponse(
                'Too many requests',
                Response::HTTP_TOO_MANY_REQUESTS,
                'rate_limit_error',
            );
        } catch (ValidationException $e) {
            return $this->createErrorResponse(
                $e->getMessage(),
                Response::HTTP_BAD_REQUEST,
                'validation_error',
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
     * @return array<string, mixed>
     */
    private function extractContext(Request $request): array
    {
        return [
            'include_headers' => $request->input('include_headers', false),
            'client_identifier' => $request->ip() ?? 'unknown',
            'headers' => $request->headers->all(),
            'cookies' => $request->cookies->all(),
            'files' => $request->allFiles(),
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
