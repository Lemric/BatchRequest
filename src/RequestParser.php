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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
    ): Response {
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

    private function getBatchRequestResponse(TransitionCollection $transitions, HttpKernelInterface $httpKernel, bool $includeHeaders): Response
    {
        $response = new StreamedResponse();
        $response->setCallback(function() use($transitions, $httpKernel, $includeHeaders) {
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);
            echo '[';
            $first = true;
            $batchSize = 1000;
            $counter = 0;
            try {
                foreach ($this->generateBatchResponse($transitions, $httpKernel, $includeHeaders) as $item) {
                    if (!$first) {
                        echo ',';
                    } else {
                        $first = false;
                    }
                    echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (++$counter % $batchSize === 0) {
                        flush();
                    }
                }
            } catch (\Exception $e) {
                if (!$first) {
                    echo ',';
                }
                echo json_encode(['error' => $e->getMessage()]);
            }

            echo ']';
            flush();
        });

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Transfer-Encoding', 'chunked');

        return $response;
    }

    /**
     * @throws AssertionFailedException
     */
    private function generateBatchResponse(TransitionCollection $transitions, HttpKernelInterface $httpKernel, bool $includeHeaders): Generator
    {
        $handler = new FiberTransactionHandler();
        foreach ($transitions->map(fn(Transaction $transaction): Response => $handler->handleTransaction($transaction, $httpKernel)) as $response) {
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

    private function decodeJsonContent(array|string $content): mixed
    {
        if(is_array($content)) {
            return $content;
        }
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