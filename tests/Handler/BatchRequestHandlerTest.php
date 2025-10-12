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

use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\Handler\BatchRequestHandler;
use Lemric\BatchRequest\Handler\ProcessBatchRequestCommand;
use Lemric\BatchRequest\Handler\TransactionExecutorInterface;
use Lemric\BatchRequest\Model\BatchRequest;
use Lemric\BatchRequest\Transaction;
use Lemric\BatchRequest\Validator\ValidatorInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BatchRequestHandlerTest extends TestCase
{
    private TransactionExecutorInterface $executor;

    private ValidatorInterface $validator;

    private LoggerInterface $logger;

    private BatchRequestHandler $handler;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->executor = $this->createMock(TransactionExecutorInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new BatchRequestHandler(
            $this->executor,
            $this->validator,
            $this->logger
        );
    }

    public function testHandleExecutesAllTransactions(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ];

        $batchRequest = new BatchRequest($transactions);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(['code' => 200, 'body' => []]);

        $response = $this->handler->handle($command);

        $this->assertCount(2, $response->getResponses());
        $this->assertTrue($response->isSuccessful());
    }

    public function testHandleValidatesBatchRequest(): void
    {
        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
        ]);

        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->with($batchRequest);

        $this->executor
            ->method('execute')
            ->willReturn(['code' => 200, 'body' => []]);

        $this->handler->handle($command);
    }

    public function testHandleThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $batchRequest = new BatchRequest([
            new Transaction('INVALID', '/api/posts'),
        ]);

        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->willThrowException(ValidationException::invalidMethod('INVALID'));

        $this->handler->handle($command);
    }

    public function testHandleContinuesOnTransactionFailure(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('GET', '/api/users'),
        ];

        $batchRequest = new BatchRequest($transactions);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                ['code' => 200, 'body' => []],
                $this->throwException(new \RuntimeException('Failed'))
            );

        $response = $this->handler->handle($command);

        $this->assertCount(2, $response->getResponses());
        $this->assertSame(200, $response->getResponse(0)['code']);
        $this->assertSame(500, $response->getResponse(1)['code']);
        $this->assertFalse($response->isSuccessful());
    }

    public function testHandleRemovesHeadersWhenNotIncluded(): void
    {
        $batchRequest = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            false
        );

        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->method('execute')
            ->willReturn([
                'code' => 200,
                'body' => [],
                'headers' => ['Content-Type' => 'application/json'],
            ]);

        $response = $this->handler->handle($command);

        $firstResponse = $response->getResponse(0);
        $this->assertArrayNotHasKey('headers', $firstResponse);
    }

    public function testHandleKeepsHeadersWhenIncluded(): void
    {
        $batchRequest = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            true
        );

        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->method('execute')
            ->willReturn([
                'code' => 200,
                'body' => [],
                'headers' => ['Content-Type' => 'application/json'],
            ]);

        $response = $this->handler->handle($command);

        $firstResponse = $response->getResponse(0);
        $this->assertArrayHasKey('headers', $firstResponse);
        $this->assertSame(['Content-Type' => 'application/json'], $firstResponse['headers']);
    }

    public function testHandleLogsTransactionExecution(): void
    {
        $transaction = new Transaction('GET', '/api/posts');
        $batchRequest = new BatchRequest([$transaction]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->method('execute')
            ->willReturn(['code' => 200, 'body' => []]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('debug');

        $this->handler->handle($command);
    }

    public function testHandleLogsErrors(): void
    {
        $transaction = new Transaction('GET', '/api/posts');
        $batchRequest = new BatchRequest([$transaction]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->method('execute')
            ->willThrowException(new \RuntimeException('Test error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Transaction execution failed',
                $this->callback(function ($context) {
                    return isset($context['error']) && 'Test error' === $context['error'];
                })
            );

        $this->handler->handle($command);
    }

    public function testHandleReturnsErrorResponseForFailedTransaction(): void
    {
        $transaction = new Transaction('GET', '/api/posts');
        $batchRequest = new BatchRequest([$transaction]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->method('execute')
            ->willThrowException(new \RuntimeException('Database error'));

        $response = $this->handler->handle($command);

        $this->assertCount(1, $response->getResponses());
        $firstResponse = $response->getResponse(0);

        $this->assertSame(500, $firstResponse['code']);
        $this->assertArrayHasKey('error', $firstResponse['body']);
        $this->assertSame('ExecutionException', $firstResponse['body']['error']['type']);
        $this->assertSame('Database error', $firstResponse['body']['error']['message']);
    }

    public function testHandleWithoutLogger(): void
    {
        $handler = new BatchRequestHandler($this->executor, $this->validator);

        $transaction = new Transaction('GET', '/api/posts');
        $batchRequest = new BatchRequest([$transaction]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->method('execute')
            ->willReturn(['code' => 200, 'body' => []]);

        $response = $handler->handle($command);

        $this->assertTrue($response->isSuccessful());
    }

    public function testHandleMixedSuccessAndFailure(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
            new Transaction('DELETE', '/api/posts/1'),
        ];

        $batchRequest = new BatchRequest($transactions);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->executor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                ['code' => 200, 'body' => ['id' => 1]],
                $this->throwException(new \RuntimeException('Validation failed')),
                ['code' => 204, 'body' => []]
            );

        $response = $this->handler->handle($command);

        $this->assertCount(3, $response->getResponses());
        $this->assertSame(200, $response->getResponse(0)['code']);
        $this->assertSame(500, $response->getResponse(1)['code']);
        $this->assertSame(204, $response->getResponse(2)['code']);
        $this->assertSame(1, $response->getFailureCount());
        $this->assertFalse($response->isSuccessful());
    }
}