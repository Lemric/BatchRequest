<?php

namespace Lemric\BatchRequest\Http;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use function count;
use function is_array;
use function is_string;
use const ARRAY_FILTER_USE_KEY;
use const JSON_THROW_ON_ERROR;

class BatchRequest
{
    public function handle(Request $request = null): JsonResponse
    {
        $request = null === $request ? Request::capture() : $request;

        return $this->parseRequest($request);
    }

    private function parseRequest(Request $request): JsonResponse
    {
        try {
            $transactions = $this->getTransactions($request);
        } catch (HttpException $e) {
            return new JsonResponse([
                'result' => 'error',
                'errors' => [
                    [
                        'message' => $e->getMessage(),
                        'type' => 'client_error',
                    ],
                ],
            ], $e->getStatusCode());
        } catch (Exception $e) {
            return new JsonResponse([
                'result' => 'error',
                'errors' => [
                    ['message' => $e->getMessage(), 'type' => 'system_error'],
                ],
            ], SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->getBatchRequestResponse($transactions);
    }

    /**
     * @throws JsonException
     */
    private function getTransactions(Request $request): array
    {
        if (!empty($request->getContent())) {
            $requests = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } elseif (is_string($request->get('data'))) {
            $requests = json_decode($request->get('data'), true, 512, JSON_THROW_ON_ERROR);
        } else {
            $requests = $request->get('data');
        }
        if (!is_array($requests)) {
            throw new HttpException(400, 'Invalid request');
        }

        return array_map(function ($batchedRequest) use ($request) {
            $method = $batchedRequest['method'] ?? 'GET';
            $server = $request->server->all();
            $server['IS_INTERNAL'] = true;
            $parameters = $this->getParameters($batchedRequest);
            $newRequest = Request::create(
                $batchedRequest['relative_url'],
                $method,
                $parameters,
                $request->cookies->all(),
                $request->files->all(),
                $server,
                json_encode($parameters, JSON_THROW_ON_ERROR)
            );

            $newRequest->headers->set('Authorization', $request->headers->get('Authorization'));

            return (new Transaction())->setRequest($newRequest);
        }, $requests);
    }

    private function getBatchRequestResponse(array $transactions): JsonResponse
    {
        return $this->generateBatchResponseFromSubResponses(array_map(function ($transaction) {
            try {
                $transaction->response = app()->handle($transaction->request);
            } catch (Exception $ignored) {
            }

            return $transaction;
        }, $transactions));
    }

    private function generateBatchResponseFromSubResponses(array $transactions): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set('Content-Type', 'application/json');

        $contentForSubResponses = [];
        foreach ($transactions as $key => $transaction) {
            $transaction->response->headers->set('X-BrandOriented-Request-Uri', $transaction->request->getRequestUri());
            $headers = array_map(static function ($item) {
                $item = is_array($item) ? end($item) : $item;
                if ('false' === $item) {
                    $item = false;
                } elseif ('true' === $item) {
                    $item = true;
                }

                return $item;
            }, $transaction->response->headers->all());

            $headers = array_filter($headers, static function ($item) {
                return Str::startsWith($item, 'x-debug-token')
                    || Str::startsWith($item, 'x-brandoriented')
                    || 'content-type' === $item
                    || 'date' === $item;
            }, ARRAY_FILTER_USE_KEY);
            $headers['content-type'] ??= 'application/json';

            $content = $transaction->response->getContent();
            if ('application/json' === $headers['content-type']) {
                try {
                    $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $content = [];
                }
            }
            $contentForSubResponses[$key] = [
                'code' => $transaction->response->getStatusCode(),
                'body' => $content,
            ];
        }
        $response->setContent(json_encode($contentForSubResponses));

        return $response;
    }

    private function getParameters($batchedRequest): array
    {
        return array_merge($this->getPayloadParameters($batchedRequest), $this->getQueryParameters($batchedRequest));
    }

    private function getQueryParameters($batchedRequest): array
    {
        $parameters = [];
        $urlSections = explode('?', $batchedRequest['relative_url']);
        if (2 === count($urlSections) && !empty($urlSections[1])) {
            $queryString = array_pop($urlSections);
            foreach (explode('&', $queryString) as $queryStringVariable) {
                $queryStringVariableParts = explode('=', $queryStringVariable);
                $parameters[$queryStringVariableParts[0]] = $queryStringVariableParts[1];
            }
        }

        return $parameters;
    }

    private function getPayloadParameters($batchedRequest): array
    {
        $parameters = [];
        if (isset($batchedRequest['body'], $batchedRequest['content-type'])) {
            if ('application/json' === $batchedRequest['content-type'] && is_array($batchedRequest['body'])) {
                return $batchedRequest['body'];
            }
            if ('application/x-www-form-urlencoded' === $batchedRequest['content-type'] && is_string($batchedRequest['body'])) {
                $explodedParameters = explode('&', $batchedRequest['body']);
                foreach ($explodedParameters as $parameterChunk) {
                    $parameter = explode('=', $parameterChunk);
                    $name = urldecode($parameter[0]);
                    $value = (isset($parameter[1]) ? urldecode($parameter[1]) : null);
                    $parameters[$name] = $value;
                }
            }
        }

        return $parameters;
    }
}
