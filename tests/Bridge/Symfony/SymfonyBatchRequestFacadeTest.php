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

namespace Lemric\BatchRequest\Tests\Bridge\Symfony;

use DateTimeImmutable;
use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\{RateLimit, RateLimiterFactory};

final class SymfonyBatchRequestFacadeTest extends TestCase
{
    public function testHandleBadRequestHttpException(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')
            ->willThrowException(new BadRequestHttpException('Invalid request'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey(0, $data);
        $this->assertSame(400, $data[0]['code']);
    }

    public function testHandleExtractsContextFromRequest(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request(
            ['include_headers' => 'true'],
            [],
            [],
            ['cookie1' => 'value1'],
            [],
            [
                'REMOTE_ADDR' => '192.168.1.1',
                'HTTP_AUTHORIZATION' => 'Bearer token',
            ],
            json_encode([['method' => 'GET', 'relative_url' => '/api/posts']]),
        );

        $response = $facade->handle($request);

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('headers', $data[0]);
    }

    public function testHandleGenericException(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey(0, $data);
        $this->assertSame(500, $data[0]['code']);
    }

    public function testHandleLogsErrors(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Batch request processing failed', $this->callback(function ($context) {
                return isset($context['exception'])
                    && isset($context['message'])
                    && isset($context['trace']);
            }));

        $facade = new SymfonyBatchRequestFacade($httpKernel, null, $logger);

        $request = new Request([], [], [], [], [], [], 'invalid json');

        $facade->handle($request);
    }

    public function testHandleNotFoundHttpException(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')
            ->willThrowException(new NotFoundHttpException('Not found'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/nonexistent'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey(0, $data);
        $this->assertSame(404, $data[0]['code']);
    }

    public function testHandleRateLimitExceeded(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);

        $retryAfter = new DateTimeImmutable('+1 hour');
        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(false);
        $rateLimit->method('getRetryAfter')->willReturn($retryAfter);

        $rateLimiter = $this->createMock(\Symfony\Component\RateLimiter\LimiterInterface::class);
        $rateLimiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createMock(RateLimiterFactory::class);
        $factory->method('create')->willReturn($rateLimiter);

        $facade = new SymfonyBatchRequestFacade($httpKernel, $factory);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('error', $data['result']);
        $this->assertArrayHasKey('errors', $data);
        // RFC 7807: top-level error responses are problem documents.
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    public function testHandleReturnsJsonResponse(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function testHandleSetsCorrectContentType(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testHandleWithCustomMaxBatchSize(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $facade = new SymfonyBatchRequestFacade($httpKernel, null, null, 2);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts/1'],
            ['method' => 'GET', 'relative_url' => '/api/posts/2'],
            ['method' => 'GET', 'relative_url' => '/api/posts/3'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $this->assertSame(500, $data[0]['code']);
    }

    public function testHandleWithLogger(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $facade = new SymfonyBatchRequestFacade($httpKernel, null, $logger);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $facade->handle($request);
    }

    public function testHandleWithoutClientIp(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $facade = new SymfonyBatchRequestFacade($httpKernel);

        $request = new Request([], [], [], [], [], [], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testHandleWithRateLimiter(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn(new Response('OK'));

        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $rateLimiter = $this->createMock(\Symfony\Component\RateLimiter\LimiterInterface::class);
        $rateLimiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createMock(RateLimiterFactory::class);
        $factory->method('create')->willReturn($rateLimiter);

        $facade = new SymfonyBatchRequestFacade($httpKernel, $factory);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]));

        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
