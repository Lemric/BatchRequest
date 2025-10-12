<?php

namespace Lemric\BatchRequest\Tests;

use Lemric\BatchRequest\BatchRequest;
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
        $httpKernel->method('handle')->willReturn(new Response('[]', 200));
        
        $this->batchRequest = new BatchRequest($httpKernel);
    }

    public function testHandleLargeBatchRequest(): void
    {
        $startTime = microtime(true);
        ini_set('memory_limit', '1024M');

        $requestData = [];
        for ($i = 0; $i < 100000; ++$i) {
            $requestData[] = ['relative_url' => '/', 'method' => 'GET'];
        }

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], json_encode($requestData));
        $response = $this->batchRequest->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        echo PHP_EOL . 'Batch requests ' . count($requestData) . PHP_EOL;
        echo 'Execution time: ' . $executionTime . 's' . PHP_EOL;

        $this->assertLessThan(1, $executionTime, 'Batch request processing took too long');
    }
}