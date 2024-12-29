<?php

namespace Lemric\BatchRequest\Tests;
error_reporting(E_ALL & ~E_DEPRECATED);

use Lemric\BatchRequest\BatchRequest;
use Lemric\BatchRequest\TransactionParameterParser;
use Lemric\BatchRequest\RequestParser;
use Lemric\BatchRequest\TransactionFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use PHPUnit\Framework\TestCase;

class BatchRequestPerformanceTest extends TestCase
{
    private BatchRequest $batchRequest;

    protected function setUp(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $this->batchRequest = new BatchRequest(
            $httpKernel
        );
    }

    public function testHandleLargeBatchRequest(): void
    {
        $startTime = microtime(true);
        ini_set('memory_limit', '2048M');
        $requestData = [];
        for ($i = 0; $i < 100000; $i++) {
            $requestData[] = ['relative_url' => '/', 'method' => 'GET'];
        }

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $response = $this->batchRequest->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        echo 'Batch requests ' . count($requestData) . PHP_EOL;
        $this->assertLessThan(5, $executionTime, 'Batch request processing took too long');
    }
}