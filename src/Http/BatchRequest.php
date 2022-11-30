<?php

namespace Lemric\BatchRequest\Http;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use function count;
use function is_array;
use function is_string;
use const JSON_THROW_ON_ERROR;

class BatchRequest
{
    private bool $includeHeaders = false;

    public function handle(Request $request = null): JsonResponse
    {
        $this->includeHeaders = !$request->query->has('include_headers') || $request->query->get('include_headers') === 'true';
        $request = null === $request ? Request::capture() : $request;

        return $this->parseRequest($request);
    }

    private function parseRequest(Request $request): JsonResponse
    {
        try {
            $responseList = $this->getTransactions($request);
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
            ], 500);
        }

        return $this->getBatchRequestResponse($responseList);
    }

    /**
     * @throws JsonException
     */
    private function getTransactions(Request $request): array
    {
        try {
            if($request->has('batch')) {
                $requests = json_decode($request->get('batch'), true, 512, JSON_THROW_ON_ERROR);
            } else if (!empty($request->getContent())) {
                $requests = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            } elseif (is_string($request->get('data'))) {
                $requests = json_decode($request->get('data'), true, 512, JSON_THROW_ON_ERROR);
            } else {
                $requests = $request->get('data');
            }
        } catch (JsonException $ignore) {
            throw new HttpException(400, 'Invalid request');
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
            $newRequest->headers->replace( $request->headers->all());

            return $newRequest;
        }, $requests);
    }

    private function getBatchRequestResponse(array $responseList): JsonResponse
    {
        return $this->generateBatchResponseFromSubResponses(array_map(function ($request) {
            return app()->handle($request);
        }, $responseList));
    }

    private function generateBatchResponseFromSubResponses(array $responseList): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set('Content-Type', 'application/json');
        $contentForSubResponses = [];
        foreach ($responseList as $key => $response) {

            $headers = array_map(static function ($item) {
                $item = is_array($item) ? end($item) : $item;
                if ('false' === $item) {
                    $item = false;
                } elseif ('true' === $item) {
                    $item = true;
                }

                return $item;
            }, $response->headers->all());

            $headers['content-type'] ??= 'application/json';

            $content = $response->getContent();
            if ('application/json' === $headers['content-type']) {
                try {
                    $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $content = [];
                }
            }
            $contentForSubResponses[$key] = [
                'code' => $response->getStatusCode(),
                'body' => $content,
            ];

            if ($this->includeHeaders === true) {
                $contentForSubResponses[$key]['headers'] = $headers;
            }
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
