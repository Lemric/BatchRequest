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
use Illuminate\Http\{Request, Response};
use Lemric\BatchRequest\Bridge\Laravel\LaravelTransactionExecutor;
use Lemric\BatchRequest\Transaction;
use PHPUnit\Framework\TestCase;

class LaravelTransactionExecutorTest extends TestCase
{
    private LaravelTransactionExecutor $executor;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(Kernel::class);
        $this->executor = new LaravelTransactionExecutor($this->kernel);
    }

    public function testExecutePostRequestWithBody(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/users',
            'method' => 'POST',
            'body' => '{"name": "John", "email": "john@example.com"}',
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $response = new Response('{"id": 1, "name": "John"}', 201);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return '/api/users' === $request->getPathInfo()
                    && 'POST' === $request->getMethod()
                    && '{"name": "John", "email": "john@example.com"}' === $request->getContent()
                    && 'application/json' === $request->headers->get('Content-Type');
            }))
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(201, $result['code']);
        $this->assertEquals(['id' => 1, 'name' => 'John'], $result['body']);
    }

    public function testExecuteRequestWithCookies(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/profile',
            'method' => 'GET',
            'cookies' => ['session' => 'abc123', 'theme' => 'dark'],
        ]);

        $response = new Response('{"profile": {}}', 200);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return 'abc123' === $request->cookies->get('session')
                    && 'dark' === $request->cookies->get('theme');
            }))
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
    }

    public function testExecuteRequestWithEmptyResponse(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/empty',
            'method' => 'GET',
        ]);

        $response = new Response('', 204);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(204, $result['code']);
        $this->assertEquals('', $result['body']);
    }

    public function testExecuteRequestWithException(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/error',
            'method' => 'GET',
        ]);

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willThrowException(new Exception('Database connection failed'));

        $result = $this->executor->execute($transaction);

        $this->assertEquals(500, $result['code']);
        $this->assertArrayHasKey('error', $result['body']);
        $this->assertEquals('ExecutionException', $result['body']['error']['type']);
        $this->assertEquals('Internal server error', $result['body']['error']['message']);
        $this->assertEquals([], $result['headers']);
    }

    public function testExecuteRequestWithFiles(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/upload',
            'method' => 'POST',
            'files' => ['file' => ['name' => 'test.txt', 'type' => 'text/plain']],
        ]);

        $response = new Response('{"uploaded": true}', 200);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return $request->files->has('file');
            }))
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
    }

    public function testExecuteRequestWithInvalidJsonResponse(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/invalid-json',
            'method' => 'GET',
        ]);

        $response = new Response('{"invalid": json}', 200);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
        $this->assertEquals('{"invalid": json}', $result['body']); // Should keep original body
    }

    public function testExecuteRequestWithMultipleHeaders(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/headers',
            'method' => 'GET',
        ]);

        $response = new Response('{}', 200);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('X-Custom-1', 'value1');
        $response->headers->set('X-Custom-2', 'value2');
        $response->headers->set('Set-Cookie', ['session=abc123', 'theme=dark']);

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
        $this->assertArrayHasKey('headers', $result);
        $this->assertEquals('application/json', $result['headers']['content-type']);
        $this->assertEquals('value1', $result['headers']['x-custom-1']);
        $this->assertEquals('value2', $result['headers']['x-custom-2']);
        $this->assertStringContainsString('theme=dark', $result['headers']['set-cookie']); // Should contain the value
    }

    public function testExecuteRequestWithNonJsonResponse(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/text',
            'method' => 'GET',
        ]);

        $response = new Response('Plain text response', 200);
        $response->headers->set('Content-Type', 'text/plain');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
        $this->assertEquals('Plain text response', $result['body']);
        $this->assertEquals('text/plain', $result['headers']['content-type']);
    }

    public function testExecuteRequestWithQueryParameters(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/users?page=1&limit=10',
            'method' => 'GET',
        ]);

        $response = new Response('{"users": []}', 200);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return '/api/users' === $request->getPathInfo()
                    && 'GET' === $request->getMethod()
                    && '1' === $request->query->get('page')
                    && '10' === $request->query->get('limit');
            }))
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
        $this->assertEquals(['users' => []], $result['body']);
    }

    public function testExecuteRequestWithServerVariables(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/test',
            'method' => 'GET',
            'server' => ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'],
        ]);

        $response = new Response('{}', 200);
        $response->headers->set('Content-Type', 'application/json');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return 'example.com' === $request->server->get('HTTP_HOST')
                    && 'on' === $request->server->get('HTTPS');
            }))
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertEquals(200, $result['code']);
    }

    public function testExecuteSuccessfulRequest(): void
    {
        $transaction = Transaction::fromArray([
            'relative_url' => '/api/users',
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer token'],
        ]);

        $response = new Response('{"id": 1, "name": "John"}', 200);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('X-Custom-Header', 'test');

        $this->kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return '/api/users' === $request->getPathInfo()
                    && 'GET' === $request->getMethod()
                    && 'Bearer token' === $request->headers->get('Authorization');
            }))
            ->willReturn($response);

        $result = $this->executor->execute($transaction);

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['code']);
        $this->assertEquals(['id' => 1, 'name' => 'John'], $result['body']);
        $this->assertArrayHasKey('headers', $result);
        $this->assertEquals('application/json', $result['headers']['content-type']);
        $this->assertEquals('test', $result['headers']['x-custom-header']);
    }
}
