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

namespace Lemric\BatchRequest\Tests\Bridge\Laravel;

use Exception;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\{JsonResponse, Request, Response};
use Lemric\BatchRequest\Bridge\Laravel\LaravelBatchRequestFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LaravelBatchRequestFacadeTest extends TestCase
{
    private LaravelBatchRequestFacade $facade;

    private Kernel $kernel;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(Kernel::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->facade = new LaravelBatchRequestFacade(
            $this->kernel,
            $this->logger,
            50,
        );
    }

    public function testExtractContextFromRequest(): void
    {
        $requestData = [['relative_url' => '/test', 'method' => 'GET']];
        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Custom', 'value');
        $request->cookies->set('session', 'abc123');

        $response = new Response('{}', 200);
        $this->kernel->method('handle')->willReturn($response);

        $response = $this->facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleEmptyBatchRequest(): void
    {
        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode([]));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function testHandleInvalidJson(): void
    {
        $request = Request::create('/batch', 'POST', [], [], [], [], 'invalid json');
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertEquals('error', $data['result']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testHandleLargeBatchRequest(): void
    {
        $requestData = [];
        for ($i = 0; $i < 1000; ++$i) {
            $requestData[] = ['relative_url' => '/test', 'method' => 'GET'];
        }

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{}', 200);
        $this->kernel->method('handle')->willReturn($response);

        $startTime = microtime(true);
        $response = $this->facade->handle($request);
        $executionTime = microtime(true) - $startTime;

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThan(1.0, $executionTime, 'Large batch request should be processed quickly');
    }

    public function testHandleValidBatchRequest(): void
    {
        $requestData = [
            ['relative_url' => '/api/users', 'method' => 'GET'],
            ['relative_url' => '/api/posts', 'method' => 'POST', 'body' => '{"title": "Test"}'],
        ];

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        // Mock successful responses
        $response1 = new Response('{"id": 1, "name": "John"}', 200);
        $response1->headers->set('Content-Type', 'application/json');

        $response2 = new Response('{"id": 2, "title": "Test"}', 201);
        $response2->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls($response1, $response2);

        $response = $this->facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertEquals(200, $data[0]['code']);
        $this->assertEquals(201, $data[1]['code']);
    }

    public function testHandleWithCustomMaxBatchSize(): void
    {
        $facade = new LaravelBatchRequestFacade($this->kernel, $this->logger, 2);

        $requestData = [
            ['relative_url' => '/api/users', 'method' => 'GET'],
            ['relative_url' => '/api/posts', 'method' => 'GET'],
            ['relative_url' => '/api/comments', 'method' => 'GET'],
        ];

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = $facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertIsArray($data);
        $this->assertCount(3, $data);

        // All should have error status due to batch size limit
        foreach ($data as $item) {
            $this->assertEquals(500, $item['code']);
        }
    }

    public function testHandleWithExecutionError(): void
    {
        $requestData = [
            ['relative_url' => '/api/users', 'method' => 'GET'],
        ];

        $request = Request::create('/batch', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willThrowException(new Exception('Database connection failed'));

        $response = $this->facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals(500, $data[0]['code']);
        $this->assertArrayHasKey('error', $data[0]['body']);
    }

    public function testHandleWithIncludeHeaders(): void
    {
        $requestData = [
            ['relative_url' => '/api/users', 'method' => 'GET'],
        ];

        $request = Request::create('/batch?include_headers=true', 'POST', [], [], [], [], json_encode($requestData));
        $request->headers->set('Content-Type', 'application/json');

        $response = new Response('{"id": 1}', 200);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('X-Custom-Header', 'test');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $response = $this->facade->handle($request);

        $data = $response->getData(true);
        $this->assertArrayHasKey('headers', $data[0]);
        $this->assertEquals('test', $data[0]['headers']['x-custom-header']);
    }
}
