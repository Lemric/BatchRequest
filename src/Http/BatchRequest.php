<?php

namespace Lemric\BatchRequest\Http;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use function count;
use function end;
use function explode;
use function is_array;
use function array_map;
use function array_pop;
use function array_merge;
use function parse_str;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

class BatchRequest
{
    private bool $includeHeaders = true;

    public function handle(Request $request = null): JsonResponse
    {
        $request = null === $request ? Request::capture() : $request;
        $this->includeHeaders = (
            ($request->has('include_headers')
                && $request->has('include_headers') != 'false')
            || $request->get('include_headers') === 'true'
        );

        return $this->parseRequest($request);
    }

    private function parseRequest(Request $request): JsonResponse
    {
        try {
            $responseList = $this->getTransactions($request);
        } catch (HttpException $e) {
            return new JsonResponse(data: [
                'result' => 'error',
                'errors' => [
                    ['message' => $e->getMessage(), 'type' => 'client_error'],
                ],
            ], status: $e->getStatusCode());
        } catch (Exception $e) {
            return new JsonResponse(data: [
                'result' => 'error',
                'errors' => [
                    ['message' => $e->getMessage(), 'type' => 'system_error'],
                ],
            ], status: 500);
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
                $requests = json_decode(
                    json: $request->get('batch'),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR
                );
            } else if (!empty($request->getContent())) {
                $requests = json_decode(
                    json: $request->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR
                );
            } elseif (is_string(value: $request->get('data'))) {
                $requests = json_decode(
                    json: $request->get('data'),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR
                );
            } else {
                $requests = $request->get('data');
            }
        } catch (JsonException $ignore) {
            throw new HttpException(400, 'Invalid request');
        }
        if (!is_array(value: $requests)) {
            throw new HttpException(400, 'Invalid request');
        }

        return array_map(callback: function ($batchedRequest) use ($request) {
            $method = $batchedRequest['method'] ?? 'GET';
            $server = $request->server->all();
            $server['IS_INTERNAL'] = true;
            $parameters = $this->getParameters($batchedRequest);
            $newRequest = Request::create(
                uri: $batchedRequest['relative_url'],
                method: $method,
                parameters: $parameters,
                cookies: $request->cookies->all(),
                files: $request->files->all(),
                server: $server,
                content: json_encode(value: $parameters, flags: JSON_THROW_ON_ERROR)
            );
            $newRequest->headers->replace(headers: $request->headers->all());

            return $newRequest;
        }, array: $requests);
    }

    private function getBatchRequestResponse(array $responseList): JsonResponse
    {
        return $this->generateBatchResponseFromSubResponses(array_map(callback: function ($request) {
            try {
                return app()->handle(request: $request, type: HttpKernelInterface::SUB_REQUEST);
            } catch (Exception $ignored) {
            }
            return null;
        }, array: $responseList));
    }

    private function generateBatchResponseFromSubResponses(array $responseList): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set('Content-Type', 'application/json');
        $contentForSubResponses = [];
        foreach ($responseList as $key => $value) {

            $headers = array_map(callback: static function ($item) {
                $item = is_array(value: $item) ? end(array: $item) : $item;
                if ('false' === $item) {
                    $item = false;
                } elseif ('true' === $item) {
                    $item = true;
                }

                return $item;
            }, array: $value->headers->all());

            $headers['content-type'] ??= 'application/json';

            $content = $value->getContent();
            if ('application/json' === $headers['content-type']) {
                try {
                    $content = json_decode(
                        json: $content,
                        associative: true,
                        flags: JSON_THROW_ON_ERROR
                    );
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
        $response->setContent(
            json_encode(
                value: $contentForSubResponses
            )
        );

        return $response;
    }

    private function getParameters(array $batchedRequest): array
    {
        return array_merge(
            $this->getPayloadParameters($batchedRequest),
            $this->getQueryParameters($batchedRequest)
        );
    }

    private function getQueryParameters(array $batchedRequest): array
    {
        $parameters = [];
        $urlSections = explode(separator: '?', string: $batchedRequest['relative_url']);
        if (2 === count(value: $urlSections) && !empty($urlSections[1])) {
            $queryString = array_pop(array: $urlSections);
            parse_str(string: $queryString, result: $parameters);
        }

        return $parameters;
    }

    private function getPayloadParameters(array $batchedRequest): array
    {
        $parameters = [];
        if (isset($batchedRequest['body'], $batchedRequest['content-type'])) {
            if ('application/json' === $batchedRequest['content-type']
                && is_array(value: $batchedRequest['body'])
            ) {
                return $batchedRequest['body'];
            }
            if ('application/x-www-form-urlencoded' === $batchedRequest['content-type']
                && is_string(value: $batchedRequest['body'])
            ) {
                parse_str(string: $batchedRequest['body'], result: $parameters);
                $parameters = array_map(callback: function($parameter) {
                    try {
                        $parameter = json_decode(
                            json: $parameter,
                            associative: true,
                            flags: JSON_THROW_ON_ERROR
                        );
                    } catch (JsonException $ignored) {
                    }
                    return $parameter;
                }, array: $parameters);
            }
        }

        return $parameters;
    }
}