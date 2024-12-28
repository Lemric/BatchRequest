<?php

namespace Lemric\BatchRequest\Tests;
error_reporting(E_ALL & ~E_DEPRECATED);

use Lemric\BatchRequest\RequestParser;
use Lemric\BatchRequest\TransactionFactory;
use Lemric\BatchRequest\TransitionCollection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use PHPUnit\Framework\TestCase;

class RequestParserTest extends TestCase
{
    private RequestParser $parser;

    private TransactionFactory $transactionFactory;

    private HttpKernelInterface $httpKernel;

    protected function setUp(): void
    {
        $this->transactionFactory = $this->createMock(TransactionFactory::class);
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
        $this->parser = new RequestParser();
    }

    public function testParseValidRequest(): void
    {
        $request = new Request();
        $this->transactionFactory->method('create')->willReturn($this->createMock(TransitionCollection::class));
        $response = $this->parser->parse($request, $this->transactionFactory, $this->httpKernel, true, null);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testParseInvalidRequest(): void
    {
        $request = new Request();

        $this->transactionFactory->method('create')
            ->willThrowException(new HttpException(400, 'Invalid request'));

        $response = $this->parser->parse($request, $this->transactionFactory, $this->httpKernel, true, null);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $response->getContent());

        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('result', $responseData);
        $this->assertSame('error', $responseData['result']);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertSame('Invalid request', $responseData['errors'][0]['message']);
        $this->assertSame('validation_error', $responseData['errors'][0]['type']);
    }
}