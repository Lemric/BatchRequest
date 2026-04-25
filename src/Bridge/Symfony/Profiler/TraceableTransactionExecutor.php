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

namespace Lemric\BatchRequest\Bridge\Symfony\Profiler;

use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\TransactionInterface;
use Throwable;
use function hrtime;
use function memory_get_usage;
use function strlen;

/**
 * Decorates a {@see TransactionExecutorInterface} and records timing,
 * payload and outcome metadata for every executed transaction so that
 * the Symfony Profiler can render a complete trace of the batch.
 *
 * The collected traces are kept in memory until they are pulled by the
 * data collector and then cleared via {@see self::reset()} which is
 * also called automatically through the `kernel.reset` tag, making the
 * service safe to reuse across requests in long-running workers
 * (FrankenPHP, Swoole, RoadRunner, FPM with `kernel.reset` enabled).
 */
final class TraceableTransactionExecutor implements TransactionExecutorInterface
{
    /**
     * Maximum size (in bytes) of any captured payload (request body or
     * response body) that we keep in memory. Larger values are truncated
     * with an explicit marker so that profiler dumps remain bounded.
     */
    private const MAX_BODY_SNAPSHOT = 16384;

    /**
     * @var list<array{
     *     method: string,
     *     uri: string,
     *     request_headers: array<string, string|array<string>>,
     *     request_body: string,
     *     request_body_truncated: bool,
     *     status: int,
     *     response_headers: array<string, string>,
     *     response_body: mixed,
     *     response_body_size: int,
     *     duration_ms: float,
     *     memory_delta: int,
     *     successful: bool,
     *     error: ?array{type: string, message: string},
     * }>
     */
    private array $traces = [];

    public function __construct(
        private readonly TransactionExecutorInterface $inner,
    ) {
    }

    public function execute(TransactionInterface $transaction): array
    {
        $startedAt = hrtime(true);
        $memoryBefore = memory_get_usage();

        $error = null;
        try {
            $response = $this->inner->execute($transaction);
        } catch (Throwable $e) {
            $error = ['type' => $e::class, 'message' => $e->getMessage()];

            $this->record($transaction, $startedAt, $memoryBefore, [
                'code' => 500,
                'body' => null,
                'headers' => [],
            ], $error);

            throw $e;
        }

        $this->record($transaction, $startedAt, $memoryBefore, $response, $this->extractInlineError($response));

        return $response;
    }

    /**
     * Returns the in-memory trace buffer. The collector consumes this in
     * its `collect()` callback.
     *
     * @return list<array<string, mixed>>
     */
    public function getTraces(): array
    {
        return $this->traces;
    }

    /**
     * Clears the trace buffer. Invoked by the Symfony container through
     * the `kernel.reset` tag between requests in long-running workers.
     */
    public function reset(): void
    {
        $this->traces = [];
    }

    /**
     * Detects the conventional `body.error.{type,message}` envelope
     * produced by {@see \Lemric\BatchRequest\Bridge\Symfony\SymfonyTransactionExecutor}
     * so that responses formatted as soft-failures (e.g. 4xx/5xx) are
     * surfaced as errors in the profiler panel.
     *
     * @param array{code: int, body: mixed, headers?: array<string, string>} $response
     *
     * @return array{type: string, message: string}|null
     */
    private function extractInlineError(array $response): ?array
    {
        if ($response['code'] < 400) {
            return null;
        }

        $body = $response['body'] ?? null;
        if (\is_array($body) && isset($body['error']['type'], $body['error']['message'])) {
            return [
                'type' => (string) $body['error']['type'],
                'message' => (string) $body['error']['message'],
            ];
        }

        return ['type' => 'HttpError', 'message' => 'HTTP '.$response['code']];
    }

    /**
     * @param array{code: int, body: mixed, headers?: array<string, string>} $response
     * @param array{type: string, message: string}|null                      $error
     */
    private function record(
        TransactionInterface $transaction,
        int $startedAt,
        int $memoryBefore,
        array $response,
        ?array $error,
    ): void {
        $duration = (hrtime(true) - $startedAt) / 1_000_000;

        $body = $transaction->getContent();
        $bodyLen = strlen($body);
        $truncated = $bodyLen > self::MAX_BODY_SNAPSHOT;

        $responseBody = $response['body'] ?? null;
        $responseSize = match (true) {
            \is_string($responseBody) => strlen($responseBody),
            \is_array($responseBody) => strlen((string) json_encode($responseBody)),
            default => 0,
        };

        $this->traces[] = [
            'method' => $transaction->getMethod(),
            'uri' => $transaction->getUri(),
            'request_headers' => $transaction->getHeaders(),
            'request_body' => $truncated ? substr($body, 0, self::MAX_BODY_SNAPSHOT) : $body,
            'request_body_truncated' => $truncated,
            'status' => $response['code'] ?? 0,
            'response_headers' => $response['headers'] ?? [],
            'response_body' => $responseBody,
            'response_body_size' => $responseSize,
            'duration_ms' => $duration,
            'memory_delta' => memory_get_usage() - $memoryBefore,
            'successful' => null === $error,
            'error' => $error,
        ];
    }
}

