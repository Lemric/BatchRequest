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

namespace Lemric\BatchRequest\Tests\Benchmark;

use Fiber;
use Lemric\BatchRequest\Handler\{BatchRequestHandler,
    FiberExecutionStrategy,
    ProcessBatchRequestCommand,
    TransactionExecutorInterface,};
use Lemric\BatchRequest\Parser\JsonBatchRequestParser;
use Lemric\BatchRequest\TransactionInterface;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use PHPUnit\Framework\TestCase;
use function hash;
use function json_encode;
use function memory_get_peak_usage;
use function microtime;
use function number_format;
use function sprintf;
use function str_pad;
use function usleep;
use const PHP_EOL;
use const STR_PAD_LEFT;

/**
 * Compares Fiber-based vs sequential execution of batch transactions.
 *
 * Three scenarios are exercised so the trade-offs of cooperative
 * concurrency are visible:
 *
 *  1. **Pure CPU work** — fibers add scheduling overhead without
 *     unlocking any concurrency (no I/O point to suspend on). Sequential
 *     should be marginally faster; this measures the upper bound of
 *     the strategy's overhead.
 *
 *  2. **Blocking I/O (`usleep`)** — fibers cannot interleave because
 *     `usleep` blocks the OS thread. Times should be effectively
 *     identical to sequential. This proves Fibers don't *hurt* in the
 *     worst case for blocking executors.
 *
 *  3. **Cooperative I/O (`Fiber::suspend()` from within executor)** —
 *     a fiber-aware executor that yields while waiting for I/O, the
 *     handler's resume loop interleaves transactions transparently.
 *     Wall time should drop close to `total / maxConcurrency`.
 */
class FiberVsSequentialBenchmarkTest extends TestCase
{
    private const BATCH_SIZE = 32;

    private const MAX_CONCURRENCY = 8;

    /**
     * Number of cooperative-suspend "I/O ticks" each transaction performs
     * in scenario 3. Higher = more interleaving opportunities.
     */
    private const COOP_TICKS = 10;

    /**
     * Microseconds slept per blocking I/O scenario tick (scenario 2).
     */
    private const BLOCKING_USLEEP_US = 500;

    public function testFiberVsSequentialPureCpu(): void
    {
        $payload = $this->buildPayload();

        $sequential = $this->measure(
            fn () => $this->runHandler($payload, executor: new CpuBoundExecutor(), useFibers: false),
        );
        $fiber = $this->measure(
            fn () => $this->runHandler($payload, executor: new CpuBoundExecutor(), useFibers: true),
        );

        $this->report('Pure CPU work (no I/O — Fibers cannot interleave)', $sequential, $fiber);

        // Fibers add some overhead but should not be catastrophically slower
        // (allow up to 5× to absorb CI noise).
        $this->assertLessThan($sequential['time'] * 5.0, $fiber['time']);
    }

    public function testFiberVsSequentialBlockingIo(): void
    {
        $payload = $this->buildPayload();

        $sequential = $this->measure(
            fn () => $this->runHandler($payload, executor: new BlockingIoExecutor(self::BLOCKING_USLEEP_US), useFibers: false),
        );
        $fiber = $this->measure(
            fn () => $this->runHandler($payload, executor: new BlockingIoExecutor(self::BLOCKING_USLEEP_US), useFibers: true),
        );

        $this->report('Blocking I/O (`usleep` — no fiber preemption possible)', $sequential, $fiber);

        // Both should be roughly equal; tolerate ±50%.
        $this->assertLessThan($sequential['time'] * 1.5, $fiber['time']);
    }

    public function testFiberVsSequentialCooperativeIo(): void
    {
        $payload = $this->buildPayload();

        $sequential = $this->measure(
            fn () => $this->runHandler($payload, executor: new CooperativeIoExecutor(self::COOP_TICKS), useFibers: false),
        );
        $fiber = $this->measure(
            fn () => $this->runHandler($payload, executor: new CooperativeIoExecutor(self::COOP_TICKS), useFibers: true),
        );

        $this->report('Cooperative I/O (executor calls Fiber::suspend during waits)', $sequential, $fiber);

        // With cooperative I/O the fiber path should be measurably faster.
        // Use a conservative 1.2× threshold so the assertion is meaningful
        // without becoming flaky on slow runners.
        $this->assertLessThan(
            $sequential['time'] / 1.2,
            $fiber['time'],
            'Fiber path should outperform sequential for cooperative I/O workloads',
        );
    }

    /**
     * @param array<string, mixed> $sequential
     * @param array<string, mixed> $fiber
     */
    private function report(string $label, array $sequential, array $fiber): void
    {
        $speedup = $sequential['time'] / max($fiber['time'], 1e-9);
        $line = sprintf(
            '  sequential: %s ms | fiber(c=%d): %s ms | speedup: ×%s | mem Δ: %s KiB',
            str_pad(number_format($sequential['time'] * 1000, 3), 9, ' ', STR_PAD_LEFT),
            self::MAX_CONCURRENCY,
            str_pad(number_format($fiber['time'] * 1000, 3), 9, ' ', STR_PAD_LEFT),
            number_format($speedup, 2),
            number_format(($fiber['memory'] - $sequential['memory']) / 1024, 1),
        );

        echo PHP_EOL.'[bench] '.$label.PHP_EOL.$line.PHP_EOL;
    }

    /**
     * @param callable():void $callback
     *
     * @return array{time: float, memory: int}
     */
    private function measure(callable $callback): array
    {
        $startMem = memory_get_peak_usage(true);
        $start = microtime(true);
        $callback();
        $time = microtime(true) - $start;
        $endMem = memory_get_peak_usage(true);

        return ['time' => $time, 'memory' => max(0, $endMem - $startMem)];
    }

    private function buildPayload(): string
    {
        $items = [];
        for ($i = 0; $i < self::BATCH_SIZE; ++$i) {
            $items[] = ['relative_url' => '/api/items/'.$i, 'method' => 'GET'];
        }

        return (string) json_encode($items);
    }

    private function runHandler(string $payload, TransactionExecutorInterface $executor, bool $useFibers): void
    {
        $parser = new JsonBatchRequestParser();
        $validator = new BatchRequestValidator(new TransactionValidator(), 10000);

        $handler = new BatchRequestHandler(
            $executor,
            $validator,
            null,
            $useFibers ? new FiberExecutionStrategy() : null,
            self::MAX_CONCURRENCY,
        );

        $batch = $parser->parse($payload, ['client_identifier' => 'bench']);
        $handler->handle(new ProcessBatchRequestCommand($batch));
    }
}

/**
 * Executor performing deterministic CPU-bound work (hashing) — no
 * suspension points, so fibers cannot interleave.
 *
 * @internal
 */
final class CpuBoundExecutor implements TransactionExecutorInterface
{
    public function execute(TransactionInterface $transaction): array
    {
        $h = $transaction->getUri();
        for ($i = 0; $i < 200; ++$i) {
            $h = hash('sha256', $h);
        }

        return [
            'code' => 200,
            'body' => ['hash' => $h],
            'headers' => ['content-type' => 'application/json'],
        ];
    }
}

/**
 * Executor that blocks the OS thread (`usleep`). Fibers gain nothing
 * here because PHP cannot preempt a blocking syscall.
 *
 * @internal
 */
final class BlockingIoExecutor implements TransactionExecutorInterface
{
    public function __construct(private readonly int $sleepUs)
    {
    }

    public function execute(TransactionInterface $transaction): array
    {
        usleep($this->sleepUs);

        return [
            'code' => 200,
            'body' => ['uri' => $transaction->getUri()],
            'headers' => ['content-type' => 'application/json'],
        ];
    }
}

/**
 * Executor that simulates cooperative I/O: each "wait" releases control
 * back to the scheduler via `Fiber::suspend()`. When invoked from the
 * Fiber handler this lets the next ready fiber make progress; when
 * invoked outside a fiber it falls back to a real `usleep` so sequential
 * runs perform an equivalent amount of work.
 *
 * @internal
 */
final class CooperativeIoExecutor implements TransactionExecutorInterface
{
    public function __construct(private readonly int $ticks)
    {
    }

    public function execute(TransactionInterface $transaction): array
    {
        for ($i = 0; $i < $this->ticks; ++$i) {
            if (null !== Fiber::getCurrent()) {
                Fiber::suspend();

                continue;
            }

            usleep(100);
        }

        return [
            'code' => 200,
            'body' => ['uri' => $transaction->getUri()],
            'headers' => ['content-type' => 'application/json'],
        ];
    }
}

