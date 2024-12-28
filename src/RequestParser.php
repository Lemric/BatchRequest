<?php

namespace Lemric\BatchRequest;

use Assert\Assertion;
use Assert\AssertionFailedException;
use Generator;
use JsonException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Throwable;

class RequestParser
{
    public function parse(
        Request $request,
        TransactionFactory $transactionFactory,
        HttpKernelInterface $httpKernel,
        bool $includeHeaders,
        ?LimiterInterface $limiter
    ): JsonResponse {
        try {
            $transitions = $transactionFactory->create($request);
            $this->checkRequestsLimit($limiter, $transitions);
            return $this->getBatchRequestResponse($transitions, $httpKernel, $includeHeaders);
        } catch (HttpException|TooManyRequestsHttpException $e) {
            return $this->createErrorResponse($e, $e->getStatusCode());
        } catch (Throwable $e) {
            return $this->createErrorResponse($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function checkRequestsLimit(?LimiterInterface $limiter, TransitionCollection $transitions): void
    {
        if ($limiter instanceof LimiterInterface) {
            $limit = $limiter->consume($transitions->size());
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsHttpException('Too many requests', Response::HTTP_TOO_MANY_REQUESTS);
            }
        }
    }

    /**
     * @throws AssertionFailedException
     */
    private function getBatchRequestResponse(TransitionCollection $transitions, HttpKernelInterface $httpKernel, bool $includeHeaders): JsonResponse
    {
        $jsonResponse = new JsonResponse();
        $jsonResponse->headers->set('Content-Type', Transaction::JSON_CONTENT_TYPE);

        $generator = $this->generateBatchResponse($transitions, $httpKernel, $includeHeaders);
        $jsonResponse->setContent(json_encode(iterator_to_array($generator)));

        return $jsonResponse;
    }

    /**
     * @throws AssertionFailedException
     */
    private function generateBatchResponse(TransitionCollection $transitions, HttpKernelInterface $httpKernel, bool $includeHeaders): Generator
    {
        foreach ($transitions->map(fn(Transaction $transaction): \Symfony\Component\HttpFoundation\Response => $transaction->handle($httpKernel)) as $response) {
            Assertion::isInstanceOf($response, Response::class);
            $headers = $this->extractHeaders($response);
            $headers['content-type'] ??= Transaction::JSON_CONTENT_TYPE;

            $content = $response->getContent() === false ? [] : $response->getContent();
            if (Transaction::JSON_CONTENT_TYPE === $headers['content-type']) {
                $content = $this->decodeJsonContent($content);
            }

            $result = [
                'code' => $response->getStatusCode() ?: Response::HTTP_OK,
                'body' => $content,
            ];

            if ($includeHeaders) {
                $result['headers'] = $headers;
            }

            yield $result;
        }
    }

    private function extractHeaders(Response $response): array
    {
        try {
            $valueHeaders = $response->headers;
        } catch (Throwable) {
            $valueHeaders = new HeaderBag();
        }

        return array_map(
            fn($item): mixed => is_array($item) ? end($item) : $item,
            $valueHeaders->all()
        );
    }

    private function decodeJsonContent(string $content): mixed
    {
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
    }

    private function createErrorResponse(Throwable $e, int $status): JsonResponse
    {
        $errorType = match ($status) {
            Response::HTTP_BAD_REQUEST => 'validation_error',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_error',
            Response::HTTP_NOT_FOUND => 'routing_error',
            default => 'system_error',
        };

        return new JsonResponse(
            [
                'result' => 'error',
                'errors' => [
                    [
                        'message' => $e->getMessage(),
                        'type' => $errorType
                    ]
                ]
            ],
            $status
        );
    }
}