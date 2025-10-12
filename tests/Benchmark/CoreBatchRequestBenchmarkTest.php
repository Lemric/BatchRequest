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

use Lemric\BatchRequest\Handler\{BatchRequestHandler, ProcessBatchRequestCommand};
use Lemric\BatchRequest\Parser\JsonBatchRequestParser;
use Lemric\BatchRequest\Transaction;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use PHPUnit\Framework\TestCase;

use const PHP_EOL;

class CoreBatchRequestBenchmarkTest extends TestCase
{
    private BatchRequestHandler $handler;

    private JsonBatchRequestParser $parser;

    protected function setUp(): void
    {
        $executor = new MockTransactionExecutor();
        $transactionValidator = new TransactionValidator();
        $validator = new BatchRequestValidator($transactionValidator, 10000);

        $this->handler = new BatchRequestHandler($executor, $validator);
        $this->parser = new JsonBatchRequestParser();
    }

    public function testExtraLargeBatchPerformance(): void
    {
        ini_set('memory_limit', '128M');
        $requestData = [];
        for ($i = 0; $i < 100000; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $context = ['include_headers' => false, 'client_identifier' => 'test'];
        $batchRequest = $this->parser->parse(json_encode($requestData), $context);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $startTime = microtime(true);
        $result = $this->handler->handle($command);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(100000, count($result->getResponses()));
        $this->assertLessThan(2.0, $executionTime, 'Extra large batch (100000 requests) should be processed in under 2s');

        echo PHP_EOL."Core Extra Large Batch (100000 requests): {$executionTime}s".PHP_EOL;
    }

    public function testLargeBatchPerformance(): void
    {
        $requestData = [];
        for ($i = 0; $i < 1000; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $context = ['include_headers' => false, 'client_identifier' => 'test'];
        $batchRequest = $this->parser->parse(json_encode($requestData), $context);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $startTime = microtime(true);
        $result = $this->handler->handle($command);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(1000, count($result->getResponses()));
        $this->assertLessThan(1.0, $executionTime, 'Large batch (1000 requests) should be processed in under 1s');

        echo PHP_EOL."Core Large Batch (1000 requests): {$executionTime}s".PHP_EOL;
    }

    public function testMediumBatchPerformance(): void
    {
        $requestData = [];
        for ($i = 0; $i < 100; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $context = ['include_headers' => false, 'client_identifier' => 'test'];
        $batchRequest = $this->parser->parse(json_encode($requestData), $context);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $startTime = microtime(true);
        $result = $this->handler->handle($command);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(100, count($result->getResponses()));
        $this->assertLessThan(0.5, $executionTime, 'Medium batch (100 requests) should be processed in under 0.5s');

        echo PHP_EOL."Core Medium Batch (100 requests): {$executionTime}s".PHP_EOL;
    }

    public function testMemoryUsage(): void
    {
        $requestData = [];
        for ($i = 0; $i < 1000; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $context = ['include_headers' => false, 'client_identifier' => 'test'];
        $batchRequest = $this->parser->parse(json_encode($requestData), $context);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $memoryBefore = memory_get_usage(true);
        $result = $this->handler->handle($command);
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;

        $this->assertEquals(1000, count($result->getResponses()));
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be under 50MB for 1000 requests');

        echo PHP_EOL.'Core Memory Usage (1000 requests): '.round($memoryUsed / 1024 / 1024, 2).'MB'.PHP_EOL;
    }

    public function testSmallBatchPerformance(): void
    {
        $requestData = [];
        for ($i = 0; $i < 10; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $context = ['include_headers' => false, 'client_identifier' => 'test'];
        $batchRequest = $this->parser->parse(json_encode($requestData), $context);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $startTime = microtime(true);
        $result = $this->handler->handle($command);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(10, count($result->getResponses()));
        $this->assertLessThan(0.1, $executionTime, 'Small batch (10 requests) should be processed in under 0.1s');

        echo PHP_EOL."Core Small Batch (10 requests): {$executionTime}s".PHP_EOL;
    }
}

/**
 * Mock transaction executor for testing core functionality without framework dependencies.
 */
class MockTransactionExecutor implements \Lemric\BatchRequest\Handler\TransactionExecutorInterface
{
    public function execute(\Lemric\BatchRequest\TransactionInterface $transaction): array
    {
        return [
            'code' => 200,
            'body' => ['result' => 'success', 'id' => rand(1, 1000)],
            'headers' => ['content-type' => 'application/json'],
        ];
    }
}
