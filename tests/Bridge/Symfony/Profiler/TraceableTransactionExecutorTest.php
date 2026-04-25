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

use Lemric\BatchRequest\Bridge\Symfony\Profiler\TraceableTransactionExecutor;
use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\Transaction;
use Lemric\BatchRequest\TransactionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TraceableTransactionExecutorTest extends TestCase
{
    public function testRecordsErrorAndRethrowsWhenInnerThrows(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                throw new RuntimeException('boom');
            }
        };

        $executor = new TraceableTransactionExecutor($inner);

        try {
            $executor->execute(new Transaction('GET', '/api/items'));
            self::fail('Expected RuntimeException to bubble up.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $traces = $executor->getTraces();
        self::assertCount(1, $traces);
        self::assertFalse($traces[0]['successful']);
        self::assertSame(RuntimeException::class, $traces[0]['error']['type']);
        self::assertSame('boom', $traces[0]['error']['message']);
    }

    public function testRecordsSuccessfulExecution(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return [
                    'code' => 200,
                    'body' => ['ok' => true],
                    'headers' => ['Content-Type' => 'application/json'],
                ];
            }
        };

        $executor = new TraceableTransactionExecutor($inner);

        $response = $executor->execute(new Transaction(
            method: 'POST',
            uri: '/api/items',
            headers: ['Authorization' => 'Bearer token'],
            content: '{"name":"Item"}',
        ));

        self::assertSame(200, $response['code']);

        $traces = $executor->getTraces();
        self::assertCount(1, $traces);
        self::assertTrue($traces[0]['successful']);
        self::assertSame('POST', $traces[0]['method']);
        self::assertSame('/api/items', $traces[0]['uri']);
        self::assertSame(200, $traces[0]['status']);
        self::assertNull($traces[0]['error']);
        self::assertGreaterThanOrEqual(0.0, $traces[0]['duration_ms']);
        self::assertFalse($traces[0]['request_body_truncated']);
        self::assertSame('{"name":"Item"}', $traces[0]['request_body']);
    }

    public function testResetClearsTraceBuffer(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return ['code' => 204, 'body' => '', 'headers' => []];
            }
        };

        $executor = new TraceableTransactionExecutor($inner);
        $executor->execute(new Transaction('GET', '/a'));
        $executor->execute(new Transaction('GET', '/b'));
        self::assertCount(2, $executor->getTraces());

        $executor->reset();

        self::assertSame([], $executor->getTraces());
    }

    public function testTracksInlineErrorEnvelopeForFailedHttpStatus(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return [
                    'code' => 404,
                    'body' => [
                        'error' => [
                            'type' => 'NotFoundHttpException',
                            'message' => 'No route',
                        ],
                    ],
                    'headers' => [],
                ];
            }
        };

        $executor = new TraceableTransactionExecutor($inner);
        $executor->execute(new Transaction('GET', '/missing'));

        $traces = $executor->getTraces();
        self::assertFalse($traces[0]['successful']);
        self::assertSame('NotFoundHttpException', $traces[0]['error']['type']);
        self::assertSame('No route', $traces[0]['error']['message']);
    }

    public function testTruncatesOversizedRequestBody(): void
    {
        $inner = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return ['code' => 200, 'body' => null, 'headers' => []];
            }
        };

        $body = str_repeat('a', 17000);

        $executor = new TraceableTransactionExecutor($inner);
        $executor->execute(new Transaction(method: 'POST', uri: '/big', content: $body));

        $traces = $executor->getTraces();
        self::assertTrue($traces[0]['request_body_truncated']);
        self::assertLessThan(strlen($body), strlen((string) $traces[0]['request_body']));
    }
}

