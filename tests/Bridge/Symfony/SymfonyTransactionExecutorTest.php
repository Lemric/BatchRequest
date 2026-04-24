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

use Lemric\BatchRequest\Bridge\Symfony\SymfonyTransactionExecutor;
use Lemric\BatchRequest\Transaction;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\HttpKernel\Exception\{HttpException, NotFoundHttpException};
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SymfonyTransactionExecutorTest extends TestCase
{
    public function testExecuteCreatesCorrectRequest(): void
    {
        $capturedRequest = null;

        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturnCallback(function ($request, $type) use (&$capturedRequest) {
                $capturedRequest = $request;

                return new Response('OK');
            });

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction(
            'POST',
            '/api/posts',
            ['Authorization' => 'Bearer token'],
            ['title' => 'Test'],
            '{"title":"Test"}',
            ['session' => 'abc123'],
        );

        $executor->execute($transaction);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('POST', $capturedRequest->getMethod());
        $this->assertSame('/api/posts', $capturedRequest->getPathInfo());
        $this->assertSame('Bearer token', $capturedRequest->headers->get('Authorization'));
    }

    public function testExecuteExtractsHeaders(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new Response('OK', 200, [
                'Content-Type' => 'application/json',
                'X-Custom-Header' => 'value',
            ]));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('GET', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('content-type', $result['headers']);
        $this->assertArrayHasKey('x-custom-header', $result['headers']);
    }

    public function testExecuteHandlesEmptyResponse(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new Response('', 204));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('DELETE', '/api/posts/1');

        $result = $executor->execute($transaction);

        $this->assertSame(204, $result['code']);
        $this->assertSame('', $result['body']);
    }

    public function testExecuteHandlesGenericException(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willThrowException(new RuntimeException('Database error'));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('POST', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertSame(500, $result['code']);
        $this->assertArrayHasKey('error', $result['body']);
        $this->assertSame('ExecutionException', $result['body']['error']['type']);
        $this->assertSame('Internal server error', $result['body']['error']['message']);
    }

    public function testExecuteHandlesHttpException(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willThrowException(new NotFoundHttpException('Resource not found'));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('GET', '/api/posts/999');

        $result = $executor->execute($transaction);

        $this->assertSame(404, $result['code']);
        $this->assertArrayHasKey('error', $result['body']);
        $this->assertSame('NotFoundHttpException', $result['body']['error']['type']);
        $this->assertSame('Resource not found', $result['body']['error']['message']);
    }

    public function testExecuteHandlesInvalidJson(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new Response('invalid json', 200, ['Content-Type' => 'application/json']));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('GET', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertSame(200, $result['code']);
        $this->assertSame('invalid json', $result['body']);
    }

    public function testExecuteHandlesJsonResponse(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new JsonResponse(['data' => 'test'], 201));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('POST', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertSame(201, $result['code']);
        $this->assertIsArray($result['body']);
        $this->assertSame(['data' => 'test'], $result['body']);
    }

    public function testExecuteHandlesNonJsonResponse(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new Response('Plain text response', 200, ['Content-Type' => 'text/plain']));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('GET', '/api/text');

        $result = $executor->execute($transaction);

        $this->assertSame(200, $result['code']);
        $this->assertSame('Plain text response', $result['body']);
    }

    public function testExecuteHandlesResponseWithoutContent(): void
    {
        $response = $this->createMock(Response::class);
        $response->method('getContent')->willReturn(false);
        $response->method('getStatusCode')->willReturn(200);
        $response->headers = new \Symfony\Component\HttpFoundation\ResponseHeaderBag();

        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->method('handle')->willReturn($response);

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('GET', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertSame(200, $result['code']);
        $this->assertSame([], $result['body']);
    }

    public function testExecuteReturnsSuccessfulResponse(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new JsonResponse(['id' => 1], 200));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('GET', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertSame(200, $result['code']);
        $this->assertSame(['id' => 1], $result['body']);
        $this->assertArrayHasKey('headers', $result);
    }

    public function testExecuteWithCustomStatusCodeException(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willThrowException(new HttpException(422, 'Validation failed'));

        $executor = new SymfonyTransactionExecutor($httpKernel);
        $transaction = new Transaction('POST', '/api/posts');

        $result = $executor->execute($transaction);

        $this->assertSame(422, $result['code']);
        $this->assertSame('HttpException', $result['body']['error']['type']);
        $this->assertSame('Validation failed', $result['body']['error']['message']);
    }
}
