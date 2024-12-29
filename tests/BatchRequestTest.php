<?php

namespace Lemric\BatchRequest\Tests;
error_reporting(E_ALL & ~E_DEPRECATED);

use Lemric\BatchRequest\BatchRequest;
use Lemric\BatchRequest\TransactionParameterParser;
use Lemric\BatchRequest\RequestParser;
use Lemric\BatchRequest\TransactionFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class BatchRequestTest extends TestCase
{
    private BatchRequest $batchRequest;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->add('hello', new Route(path: '/', defaults: [
                '_controller' => fn(Request $request): Response => new JsonResponse(
                    []
                )], methods: ['GET']
        ));

        $matcher = new UrlMatcher($routes, new RequestContext());
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RouterListener($matcher, new RequestStack()));

        $controllerResolver = new ControllerResolver();
        $argumentResolver = new ArgumentResolver();
        $httpKernel = new HttpKernel($dispatcher, $controllerResolver, new RequestStack(), $argumentResolver);
        $this->batchRequest = new BatchRequest(
            $httpKernel
        );
    }

    public function testHandleValidRequest(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            json_encode([
                ['relative_url' => '/', 'method' => 'GET']
            ])
        );

        $response = $this->batchRequest->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
    }

    public function testHandleInvalidRequest(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            json_encode([
                ['relative_url' => '/', 'method' => 'INVALID']
            ])
        );

        $response = $this->batchRequest->handle($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $responses = json_decode($response->getContent(), true);
        foreach ($responses as $response) {
            $this->assertSame(500, $response['code']);
            $this->assertSame('No route found for "INVALID http://localhost/": Method Not Allowed (Allow: GET)', $response['body']['error']['message']);
            $this->assertSame('MethodNotAllowedHttpException', $response['body']['error']['type']);
        }

    }
}