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

use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\HttpKernelInterface;
use const PHP_EOL;

class SymfonyBatchRequestBenchmarkTest extends TestCase
{
    private SymfonyBatchRequestFacade $facade;

    private HttpKernelInterface $httpKernel;

    protected function setUp(): void
    {
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
        $this->facade = new SymfonyBatchRequestFacade($this->httpKernel);
    }

    public function testExtraLargeBatchPerformance(): void
    {
        ini_set('memory_limit', '256M');
        $requestData = [];
        for ($i = 0; $i < 100000; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{"result": "success"}', 200);
        $this->httpKernel->method('handle')->willReturn($response);

        $startTime = microtime(true);
        $result = $this->facade->handle($request);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertLessThan(2.0, $executionTime, 'Extra large batch (100000 requests) should be processed in under 2s');

        echo PHP_EOL."Symfony Extra Large Batch (100000 requests): {$executionTime}s".PHP_EOL;
    }

    public function testLargeBatchPerformance(): void
    {
        $requestData = [];
        for ($i = 0; $i < 1000; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{"result": "success"}', 200);
        $this->httpKernel->method('handle')->willReturn($response);

        $startTime = microtime(true);
        $result = $this->facade->handle($request);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertLessThan(1.0, $executionTime, 'Large batch (1000 requests) should be processed in under 1s');

        echo PHP_EOL."Symfony Large Batch (1000 requests): {$executionTime}s".PHP_EOL;
    }

    public function testMediumBatchPerformance(): void
    {
        $requestData = [];
        for ($i = 0; $i < 100; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{"result": "success"}', 200);
        $this->httpKernel->method('handle')->willReturn($response);

        $startTime = microtime(true);
        $result = $this->facade->handle($request);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertLessThan(0.5, $executionTime, 'Medium batch (100 requests) should be processed in under 0.5s');

        echo PHP_EOL."Symfony Medium Batch (100 requests): {$executionTime}s".PHP_EOL;
    }

    public function testMemoryUsage(): void
    {
        $requestData = [];
        for ($i = 0; $i < 1000; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{"result": "success"}', 200);
        $this->httpKernel->method('handle')->willReturn($response);

        $memoryBefore = memory_get_usage(true);
        $result = $this->facade->handle($request);
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be under 50MB for 1000 requests');

        echo PHP_EOL.'Symfony Memory Usage (1000 requests): '.round($memoryUsed / 1024 / 1024, 2).'MB'.PHP_EOL;
    }

    public function testSmallBatchPerformance(): void
    {
        $requestData = [];
        for ($i = 0; $i < 10; ++$i) {
            $requestData[] = ['relative_url' => '/api/test', 'method' => 'GET'];
        }

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{"result": "success"}', 200);
        $this->httpKernel->method('handle')->willReturn($response);

        $startTime = microtime(true);
        $result = $this->facade->handle($request);
        $executionTime = microtime(true) - $startTime;

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertLessThan(0.1, $executionTime, 'Small batch (10 requests) should be processed in under 0.1s');

        echo PHP_EOL."Symfony Small Batch (10 requests): {$executionTime}s".PHP_EOL;
    }
}
