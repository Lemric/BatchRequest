<?php

namespace Lemric\BatchRequest\Tests;

error_reporting(E_ALL & ~E_DEPRECATED);

use Lemric\BatchRequest\BatchRequest;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
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

        set_error_handler(static function (int $errno, string $errstr): bool {
            return true;
        });

        $this->batchRequest = new BatchRequest($httpKernel);

        restore_error_handler();
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
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

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
        $content = $response->getContent();
        $this->assertIsString($content);

        $responses = json_decode($content, true);
        $this->assertIsArray($responses);

        foreach ($responses as $singleResponse) {
            $this->assertIsArray($singleResponse);
            $this->assertArrayHasKey('code', $singleResponse);
            $this->assertSame(500, $singleResponse['code']);

            $this->assertArrayHasKey('body', $singleResponse);
            $this->assertIsArray($singleResponse['body']);

            $this->assertArrayHasKey('error', $singleResponse['body']);
            $this->assertIsArray($singleResponse['body']['error']);

            $this->assertArrayHasKey('message', $singleResponse['body']['error']);
            $this->assertStringContainsString('Method Not Allowed', $singleResponse['body']['error']['message']);

            $this->assertArrayHasKey('type', $singleResponse['body']['error']);
            $this->assertSame('MethodNotAllowedHttpException', $singleResponse['body']['error']['type']);
        }
    }
}