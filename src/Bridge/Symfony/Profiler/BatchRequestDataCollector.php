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

use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\VarDumper\Cloner\Data;
use Throwable;

/**
 * Collects every batch transaction processed during the current
 * request and exposes the data to the Symfony Profiler.
 *
 * Compatible with Symfony 6.x, 7.x and 8.x – relies only on
 * {@see DataCollector} from `symfony/http-kernel` (no framework-bundle
 * dependency required at runtime).
 */
final class BatchRequestDataCollector extends DataCollector
{
    public const NAME = 'lemric.batch_request';

    public function __construct(
        private readonly TraceableTransactionExecutor $traceableExecutor,
    ) {
        $this->data = self::emptyState();
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $traces = $this->traceableExecutor->getTraces();

        if ([] === $traces) {
            return;
        }

        $totalDuration = 0.0;
        $failures = 0;
        $bytes = 0;
        $items = [];

        foreach ($traces as $i => $trace) {
            $totalDuration += (float) $trace['duration_ms'];
            $bytes += (int) $trace['response_body_size'];
            if (!($trace['successful'] ?? false)) {
                ++$failures;
            }

            $items[] = [
                'index' => $i,
                'method' => (string) $trace['method'],
                'uri' => (string) $trace['uri'],
                'status' => (int) $trace['status'],
                'duration_ms' => (float) $trace['duration_ms'],
                'memory_delta' => (int) $trace['memory_delta'],
                'successful' => (bool) $trace['successful'],
                'error' => $trace['error'],
                'request_headers' => $this->cloneVar($trace['request_headers']),
                'request_body' => $this->cloneVar($trace['request_body']),
                'request_body_truncated' => (bool) $trace['request_body_truncated'],
                'response_headers' => $this->cloneVar($trace['response_headers']),
                'response_body' => $this->cloneVar($trace['response_body']),
                'response_body_size' => (int) $trace['response_body_size'],
            ];
        }

        $this->data = [
            'total' => count($items),
            'failures' => $failures,
            'duration_ms' => $totalDuration,
            'response_bytes' => $bytes,
            'request_uri' => $request->getRequestUri(),
            'request_method' => $request->getMethod(),
            'transactions' => $items,
        ];
    }

    public function getDurationMs(): float
    {
        return (float) ($this->data['duration_ms'] ?? 0.0);
    }

    public function getFailures(): int
    {
        return (int) ($this->data['failures'] ?? 0);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getRequestMethod(): string
    {
        return (string) ($this->data['request_method'] ?? '');
    }

    public function getRequestUri(): string
    {
        return (string) ($this->data['request_uri'] ?? '');
    }

    public function getResponseBytes(): int
    {
        return (int) ($this->data['response_bytes'] ?? 0);
    }

    public function getTotal(): int
    {
        return (int) ($this->data['total'] ?? 0);
    }

    /**
     * @return list<array{
     *     index: int,
     *     method: string,
     *     uri: string,
     *     status: int,
     *     duration_ms: float,
     *     memory_delta: int,
     *     successful: bool,
     *     error: ?array{type: string, message: string},
     *     request_headers: Data,
     *     request_body: Data,
     *     request_body_truncated: bool,
     *     response_headers: Data,
     *     response_body: Data,
     *     response_body_size: int,
     * }>
     */
    public function getTransactions(): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $this->data['transactions'] ?? [];

        return $items;
    }

    public function hasBatch(): bool
    {
        return $this->getTotal() > 0;
    }

    public function reset(): void
    {
        $this->data = self::emptyState();
    }

    /**
     * @return array{
     *     total: int,
     *     failures: int,
     *     duration_ms: float,
     *     response_bytes: int,
     *     request_uri: string,
     *     request_method: string,
     *     transactions: list<array<string, mixed>>,
     * }
     */
    private static function emptyState(): array
    {
        return [
            'total' => 0,
            'failures' => 0,
            'duration_ms' => 0.0,
            'response_bytes' => 0,
            'request_uri' => '',
            'request_method' => '',
            'transactions' => [],
        ];
    }
}

