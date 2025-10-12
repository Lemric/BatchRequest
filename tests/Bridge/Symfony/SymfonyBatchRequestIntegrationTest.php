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

use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class SymfonyBatchRequestIntegrationTest extends TestCase
{
    private HttpKernel $httpKernel;

    private SymfonyBatchRequestFacade $facade;

    protected function setUp(): void
    {
        $routes = new RouteCollection();

        $routes->add('api_posts_list', new Route(
            '/api/posts',
            ['_controller' => function (Request $request): Response {
                return new JsonResponse(['posts' => [['id' => 1, 'title' => 'Post 1']]]);
            }],
            methods: ['GET']
        ));

        $routes->add('api_posts_create', new Route(
            '/api/posts',
            ['_controller' => function (Request $request): Response {
                $data = json_decode($request->getContent(), true);

                return new JsonResponse(['id' => 2, 'title' => $data['title'] ?? 'Untitled'], 201);
            }],
            methods: ['POST']
        ));

        $routes->add('api_posts_delete', new Route(
            '/api/posts/{id}',
            ['_controller' => function (Request $request, int $id): Response {
                return new Response('', 204);
            }],
            methods: ['DELETE']
        ));

        $matcher = new UrlMatcher($routes, new RequestContext());
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RouterListener($matcher, new RequestStack()));

        $this->httpKernel = new HttpKernel(
            $dispatcher,
            new ControllerResolver(),
            new RequestStack(),
            new ArgumentResolver()
        );

        $this->facade = new SymfonyBatchRequestFacade($this->httpKernel);
    }

    public function testHandleSimpleBatchRequest(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame(200, $data[0]['code']);
    }

    public function testHandleMultipleTransactions(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
            ['method' => 'POST', 'relative_url' => '/api/posts', 'body' => ['title' => 'New Post']],
            ['method' => 'DELETE', 'relative_url' => '/api/posts/1'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $data = json_decode($response->getContent(), true);

        $this->assertCount(3, $data);
        $this->assertSame(200, $data[0]['code']);
        $this->assertSame(201, $data[1]['code']);
        $this->assertSame(204, $data[2]['code']);
    }

    public function testHandleWithHeaders(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $request = new Request(
            ['include_headers' => 'true'],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            $content
        );

        $response = $this->facade->handle($request);
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('headers', $data[0]);
    }

    public function testHandleWithoutHeaders(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);
        $data = json_decode($response->getContent(), true);

        $this->assertArrayNotHasKey('headers', $data[0]);
    }

    public function testHandleInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], 'invalid json');
        $response = $this->facade->handle($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('error', $data['result']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testHandleInvalidMethod(): void
    {
        $content = json_encode([
            ['method' => 'INVALID', 'relative_url' => '/api/posts'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testHandlePathTraversal(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/../etc/passwd'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testHandleNotFoundRoute(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/nonexistent'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $data = json_decode($response->getContent(), true);

        $this->assertSame(404, $data[0]['code']);
        $this->assertArrayHasKey('error', $data[0]['body']);
    }

    public function testHandleWithQueryParameters(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts?page=1&limit=10'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame(200, $data[0]['code']);
    }

    public function testHandleEmptyBatch(): void
    {
        $content = json_encode([]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testHandleSetsCorrectContentType(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testHandleLargeBatch(): void
    {
        $requests = [];
        for ($i = 0; $i < 20; ++$i) {
            $requests[] = ['method' => 'GET', 'relative_url' => '/api/posts'];
        }

        $content = json_encode($requests);
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $data = json_decode($response->getContent(), true);
        $this->assertCount(20, $data);
    }

    public function testHandleExceedsBatchSizeLimit(): void
    {
        $facade = new SymfonyBatchRequestFacade($this->httpKernel, null, null, 5);

        $requests = [];
        for ($i = 0; $i < 10; ++$i) {
            $requests[] = ['method' => 'GET', 'relative_url' => '/api/posts'];
        }

        $content = json_encode($requests);
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $facade->handle($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testHandlePreservesRequestContext(): void
    {
        $content = json_encode([
            ['method' => 'POST', 'relative_url' => '/api/posts', 'body' => ['title' => 'Test']],
        ]);

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $content);
        $response = $this->facade->handle($request);

        $data = json_decode($response->getContent(), true);
        $this->assertSame(201, $data[0]['code']);
        $this->assertSame('Test', $data[0]['body']['title']);
    }
}