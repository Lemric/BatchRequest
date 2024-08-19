<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */

namespace Lemric\BatchRequest;

use Assert\Assertion;
use Assert\AssertionFailedException;
use Error;
use Exception;
use Generator;
use JsonException;
use Symfony\Component\HttpFoundation\{HeaderBag, JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\{Exception\TooManyRequestsHttpException, HttpKernelInterface};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;
use function array_map;
use function end;
use function is_array;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class BatchRequest
{
    private bool $includeHeaders = false;

    private ?LimiterInterface $limiter = null;

    public function __construct(private readonly HttpKernelInterface $httpKernel,
                                private readonly ?RateLimiterFactory $rateLimiterFactory = null)
    {

    }

    public function handle(Request $request): JsonResponse
    {
        $this->includeHeaders = (($request->request->get('include_headers') ?? $request->query->get('include_headers')) === 'true');
        $this->limiter = $this->rateLimiterFactory?->create($request->getClientIp());

        return $this->parseRequest($request);
    }

    /**
     * @throws AssertionFailedException
     */
    private function generateBatchResponse(TransitionCollection $transitionCollection): Generator
    {
        $transitionCollection = $transitionCollection->map(fn(Transaction $transaction): Response => $transaction->handle($this->httpKernel));
        foreach ($transitionCollection as $value) {
            Assertion::isInstanceOf($value, Response::class);
            try {
                $valueHeaders = $value->headers;
            } catch (Error) {
                $valueHeaders = new HeaderBag();
            }

            $headers = array_map(callback: static function ($item) {
                $item = is_array($item) ? end($item) : $item;
                return $item === 'false' ? false : ($item === 'true' ? true : $item);
            }, array: isset($valueHeaders)  ? [] : $valueHeaders->all());

            $headers['content-type'] ??= Transaction::JSON_CONTENT_TYPE;
            $content = $value->getContent();
            if (Transaction::JSON_CONTENT_TYPE === $headers['content-type']) {
                try {
                    $content = json_decode(
                        json: (string) $content,
                        associative: true,
                        flags: JSON_THROW_ON_ERROR
                    );
                } catch (JsonException) {
                    $content = [];
                }
            }

            $response = [
                'code' => 0 === $value->getStatusCode() ? Response::HTTP_OK : $value->getStatusCode(),
                'body' => $content,
            ];

            if ($this->includeHeaders) {
                $response['headers'] = $headers;
            }

            yield $response;
        }
    }

    /**
     * @throws AssertionFailedException
     */
    private function getBatchRequestResponse(TransitionCollection $transitionCollection): JsonResponse
    {
        $jsonResponse = new JsonResponse();
        $jsonResponse->headers->set('Content-Type', Transaction::JSON_CONTENT_TYPE);
        $generator = $this->generateBatchResponse($transitionCollection);
        $jsonResponse->setContent(
            json_encode(
                value: iterator_to_array($generator)
            )
        );

        return $jsonResponse;
    }

    private function getTransactions(Request $request): TransitionCollection
    {
        try {
            $content = $request->getContent();
            if (json_validate($content)) {
                return new TransitionCollection(json_decode(
                    json: $content,
                    associative: true,
                    flags: JSON_THROW_ON_ERROR
                ), $request);
            }

            throw new HttpException(Response::HTTP_BAD_REQUEST, 'Invalid request');
        } catch (JsonException $jsonException) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, sprintf('Invalid request: %s', $jsonException->getMessage()));
        }
    }

    private function parseRequest(Request $request): JsonResponse
    {
        try {
            $transitions = $this->getTransactions($request);
            $this->checkRequestsLimit($transitions);
            return $this->getBatchRequestResponse($transitions);
        } catch (HttpException|TooManyRequestsHttpException $e) {
            return new JsonResponse(data: [
                'result' => 'error',
                'errors' => [
                    ['message' => $e->getMessage(), 'type' => 'client_error'],
                ],
            ], status: $e->getStatusCode());
        } catch (Exception|Throwable $e) {
            return new JsonResponse(data: [
                'result' => 'error',
                'errors' => [
                    ['message' => $e->getMessage(), 'type' => 'system_error'],
                ],
            ], status: Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function checkRequestsLimit(TransitionCollection $transitions): void
    {
        if($this->limiter instanceof LimiterInterface) {
            $limit = $this->limiter->consume($transitions->size());
            if (false === $limit->isAccepted()) {
                throw new TooManyRequestsHttpException(message: 'Too any requests', code: Response::HTTP_TOO_MANY_REQUESTS);
            }
        }
    }
}
