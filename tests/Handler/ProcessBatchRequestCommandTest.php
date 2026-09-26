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

namespace Lemric\BatchRequest\Tests\Handler;

use Lemric\BatchRequest\Handler\{BatchRequestHandler,
    FiberExecutionStrategy,
    ProcessBatchRequestCommand,
    TransactionExecutorInterface};
use Lemric\BatchRequest\Model\BatchRequest;
use Lemric\BatchRequest\Transaction;
use Lemric\BatchRequest\TransactionInterface;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProcessBatchRequestCommandTest extends TestCase
{
    public function testCommandIsImmutable(): void
    {
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $retrievedBatchRequest = $command->getBatchRequest();

        $this->assertSame($batchRequest, $retrievedBatchRequest);
    }

    public function testCommandPreservesMetadata(): void
    {
        $batchRequest = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            true,
            '127.0.0.1',
            ['custom' => 'metadata'],
        );

        $command = new ProcessBatchRequestCommand($batchRequest);
        $result = $command->getBatchRequest();

        $this->assertTrue($result->shouldIncludeHeaders());
        $this->assertSame('127.0.0.1', $result->getClientIdentifier());
        $this->assertSame(['custom' => 'metadata'], $result->getMetadata());
    }

    public function testCommandWithEmptyBatchRequest(): void
    {
        $batchRequest = new BatchRequest([]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $result = $command->getBatchRequest();

        $this->assertCount(0, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testCommandWithLargeBatchRequest(): void
    {
        $transactions = [];
        for ($i = 0; $i < 100; ++$i) {
            $transactions[] = new Transaction('GET', "/api/posts/{$i}");
        }

        $batchRequest = new BatchRequest($transactions);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->assertCount(100, $command->getBatchRequest());
    }

    public function testConstructorSetsBatchRequest(): void
    {
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->assertSame($batchRequest, $command->getBatchRequest());
    }

    public function testGetBatchRequestReturnsSameInstance(): void
    {
        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ]);

        $command = new ProcessBatchRequestCommand($batchRequest);

        $result = $command->getBatchRequest();

        $this->assertSame($batchRequest, $result);
        $this->assertCount(2, $result);
    }

    public function testHandlerReturnsSyntheticErrorsWhenValidationFails(): void
    {
        $executor = $this->createMock(TransactionExecutorInterface::class);
        $executor->expects($this->never())->method('execute');

        $handler = new BatchRequestHandler(
            $executor,
            new BatchRequestValidator(new TransactionValidator(), 1),
        );

        $batch = new BatchRequest([
            new Transaction('GET', '/api/a'),
            new Transaction('GET', '/api/b'),
        ]);

        $response = $handler->handle(new ProcessBatchRequestCommand($batch));

        $this->assertCount(2, $response->getResponses());
        $this->assertSame(500, $response->getResponses()[0]['code']);
        $this->assertSame(500, $response->getResponses()[1]['code']);
        $this->assertSame('MethodNotAllowedHttpException', $response->getResponses()[0]['body']['error']['type']);
        $this->assertStringContainsString('exceeds limit', $response->getResponses()[0]['body']['error']['message']);
    }

    public function testHandlerEmptyBatchAfterFailedValidationReturnsNoItems(): void
    {
        $executor = $this->createMock(TransactionExecutorInterface::class);
        $executor->expects($this->never())->method('execute');

        $handler = new BatchRequestHandler(
            $executor,
            new BatchRequestValidator(new TransactionValidator()),
        );

        $response = $handler->handle(new ProcessBatchRequestCommand(new BatchRequest([])));

        $this->assertSame([], $response->getResponses());
        $this->assertTrue($response->isSuccessful());
    }

    public function testHandlerStripsHeadersUnlessRequested(): void
    {
        $executor = $this->createMock(TransactionExecutorInterface::class);
        $executor->method('execute')->willReturn([
            'code' => 200,
            'body' => ['ok' => true],
            'headers' => ['X-Test' => '1'],
        ]);

        $handler = new BatchRequestHandler(
            $executor,
            new BatchRequestValidator(new TransactionValidator()),
        );

        $without = $handler->handle(new ProcessBatchRequestCommand(
            new BatchRequest([new Transaction('GET', '/api/a')], includeHeaders: false),
        ));
        $with = $handler->handle(new ProcessBatchRequestCommand(
            new BatchRequest([new Transaction('GET', '/api/a')], includeHeaders: true),
        ));

        $this->assertArrayNotHasKey('headers', $without->getResponses()[0]);
        $this->assertSame(['X-Test' => '1'], $with->getResponses()[0]['headers']);
    }

    public function testHandlerSanitizesExecutorExceptionsForClients(): void
    {
        $executor = $this->createMock(TransactionExecutorInterface::class);
        $executor->method('execute')->willThrowException(
            new RuntimeException('password=super-secret token=abc'),
        );

        $handler = new BatchRequestHandler(
            $executor,
            new BatchRequestValidator(new TransactionValidator()),
        );

        $response = $handler->handle(new ProcessBatchRequestCommand(
            new BatchRequest([new Transaction('GET', '/api/a')]),
        ));

        $this->assertSame(500, $response->getResponses()[0]['code']);
        $this->assertSame('Internal server error', $response->getResponses()[0]['body']['error']['message']);
        $this->assertSame('ExecutionException', $response->getResponses()[0]['body']['error']['type']);
    }

    public function testHandlerPreservesResponseOrderWithFiberStrategy(): void
    {
        $executor = new class implements TransactionExecutorInterface {
            public function execute(TransactionInterface $transaction): array
            {
                return [
                    'code' => 200,
                    'body' => ['uri' => $transaction->getUri()],
                    'headers' => [],
                ];
            }
        };

        $handler = new BatchRequestHandler(
            $executor,
            new BatchRequestValidator(new TransactionValidator()),
            strategy: new FiberExecutionStrategy(),
            maxConcurrency: 4,
        );

        $batch = new BatchRequest([
            new Transaction('GET', '/a'),
            new Transaction('GET', '/b'),
            new Transaction('POST', '/c'),
            new Transaction('GET', '/d'),
            new Transaction('HEAD', '/e'),
        ]);

        $uris = array_map(
            static fn (array $item): string => $item['body']['uri'],
            $handler->handle(new ProcessBatchRequestCommand($batch))->getResponses(),
        );

        $this->assertSame(['/a', '/b', '/c', '/d', '/e'], $uris);
    }

    public function testFiberStrategyGroupsReadsAndIsolatesWrites(): void
    {
        $strategy = new FiberExecutionStrategy();
        $transactions = [
            0 => new Transaction('GET', '/a'),
            1 => new Transaction('HEAD', '/b'),
            2 => new Transaction('POST', '/c'),
            3 => new Transaction('GET', '/d'),
            4 => new Transaction('delete', '/e'),
        ];

        $groups = $strategy->groupTransactions($transactions);

        $this->assertCount(4, $groups);
        $this->assertSame([0, 1], array_keys($groups[0]));
        $this->assertTrue($strategy->canExecuteInParallel($groups[0]));
        $this->assertSame([2], array_keys($groups[1]));
        $this->assertFalse($strategy->canExecuteInParallel($groups[1]));
        $this->assertSame([3], array_keys($groups[2]));
        $this->assertSame([4], array_keys($groups[3]));
        $this->assertFalse($strategy->canExecuteInParallel([]));
        $this->assertTrue($strategy->isReadOnly(new Transaction('get', '/x')));
        $this->assertFalse($strategy->isReadOnly(new Transaction('PATCH', '/x')));
    }

    public function testHandlerMaxConcurrencyOneForcesSerialPath(): void
    {
        $calls = new \ArrayObject();
        $executor = new class($calls) implements TransactionExecutorInterface {
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function execute(TransactionInterface $transaction): array
            {
                $this->calls[] = $transaction->getUri();

                return ['code' => 200, 'body' => [], 'headers' => []];
            }
        };

        $handler = new BatchRequestHandler(
            $executor,
            new BatchRequestValidator(new TransactionValidator()),
            strategy: new FiberExecutionStrategy(),
            maxConcurrency: 1,
        );

        $handler->handle(new ProcessBatchRequestCommand(new BatchRequest([
            new Transaction('GET', '/1'),
            new Transaction('GET', '/2'),
        ])));

        $this->assertSame(['/1', '/2'], $calls->getArrayCopy());
    }
}
