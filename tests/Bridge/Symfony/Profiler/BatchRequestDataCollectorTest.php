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

namespace Lemric\BatchRequest\Tests\Bridge\Symfony\Profiler;

use Lemric\BatchRequest\Bridge\Symfony\Profiler\{BatchRequestDataCollector, TraceableTransactionExecutor};
use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\Transaction;
use Lemric\BatchRequest\TransactionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, Response};

final class BatchRequestDataCollectorTest extends TestCase
{
    public function testCollectAggregatesTracesFromExecutor(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return match ($transaction->getUri()) {
                    '/ok' => ['code' => 200, 'body' => ['ok' => true], 'headers' => []],
                    default => ['code' => 500, 'body' => ['error' => ['type' => 'X', 'message' => 'fail']], 'headers' => []],
                };
            }
        };

        $traceable = new TraceableTransactionExecutor($inner);
        $traceable->execute(new Transaction('GET', '/ok'));
        $traceable->execute(new Transaction('GET', '/fail'));

        $collector = new BatchRequestDataCollector($traceable);
        $collector->collect(Request::create('/batch', 'POST'), new Response());

        self::assertSame(BatchRequestDataCollector::NAME, $collector->getName());
        self::assertSame(2, $collector->getTotal());
        self::assertSame(1, $collector->getFailures());
        self::assertSame('POST', $collector->getRequestMethod());
        self::assertSame('/batch', $collector->getRequestUri());
        self::assertGreaterThanOrEqual(0.0, $collector->getDurationMs());

        $tx = $collector->getTransactions();
        self::assertCount(2, $tx);
        self::assertSame(200, $tx[0]['status']);
        self::assertTrue($tx[0]['successful']);
        self::assertSame(500, $tx[1]['status']);
        self::assertFalse($tx[1]['successful']);
        self::assertTrue($collector->hasBatch());
    }

    public function testEmptyStateWhenNoTraces(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return ['code' => 200, 'body' => null, 'headers' => []];
            }
        };

        $collector = new BatchRequestDataCollector(new TraceableTransactionExecutor($inner));
        $collector->collect(Request::create('/'), new Response());

        self::assertSame(0, $collector->getTotal());
        self::assertSame(0, $collector->getFailures());
        self::assertFalse($collector->hasBatch());
        self::assertSame([], $collector->getTransactions());
    }

    public function testResetClearsCollectedData(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return ['code' => 200, 'body' => null, 'headers' => []];
            }
        };

        $traceable = new TraceableTransactionExecutor($inner);
        $traceable->execute(new Transaction('GET', '/a'));

        $collector = new BatchRequestDataCollector($traceable);
        $collector->collect(Request::create('/'), new Response());
        self::assertSame(1, $collector->getTotal());

        $collector->reset();

        self::assertSame(0, $collector->getTotal());
        self::assertSame([], $collector->getTransactions());
    }
}

