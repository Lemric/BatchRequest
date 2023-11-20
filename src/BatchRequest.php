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

use function array_map;
use function array_merge;
use function array_pop;
use function count;
use function end;

use Exception;

use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function parse_str;

use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\{HttpKernelInterface};

final class BatchRequest
{
    private bool $includeHeaders = true;

    public function __construct(private readonly HttpKernelInterface $httpKernel)
    {
    }

    public function handle(Request $request = null): JsonResponse
    {
        $this->includeHeaders = (
            ($request->request->has('include_headers')
                && 'false' != $request->request->has('include_headers'))
            || 'true' === $request->request->get('include_headers') ||
            ($request->query->has('include_headers')
                && 'false' != $request->query->has('include_headers'))
            || 'true' === $request->query->get('include_headers')
        );

        return $this->parseRequest($request);
    }

    private function generateBatchResponseFromSubResponses(array $responseList): JsonResponse
    {
        $jsonResponse = new JsonResponse();
        $jsonResponse->headers->set('Content-Type', 'application/json');
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
            }, array: null !== $value->headers ? $value->headers->all() : []);

            $headers['content-type'] ??= 'application/json';

            $content = $value->getContent();
            if ('application/json' === $headers['content-type']) {
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

            $jsonResponse->setStatusCode($value->getStatusCode());
            $contentForSubResponses[$key] = [
                'code' => $jsonResponse->getStatusCode(),
                'body' => $content,
            ];

            if ($this->includeHeaders) {
                $contentForSubResponses[$key]['headers'] = $headers;
            }
        }
        $jsonResponse->setContent(
            json_encode(
                value: $contentForSubResponses
            )
        );

        return $jsonResponse;
    }

    private function getBatchRequestResponse(array $responseList): JsonResponse
    {
        return $this->generateBatchResponseFromSubResponses(array_map(callback: function ($request): ?Response {
            try {

                return $this->httpKernel->handle(request: $request, type: HttpKernelInterface::SUB_REQUEST);
            } catch (Exception) {
            }

            return null;
        }, array: $responseList));
    }

    private function getParameters(array $batchedRequest): array
    {
        return array_merge(
            $this->getPayloadParameters($batchedRequest),
            $this->getQueryParameters($batchedRequest)
        );
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
                $parameters = array_map(callback: function ($parameter) {
                    try {
                        $parameter = json_decode(
                            json: $parameter,
                            associative: true,
                            flags: JSON_THROW_ON_ERROR
                        );
                    } catch (JsonException) {
                    }

                    return $parameter;
                }, array: $parameters);
            }
        }

        return $parameters;
    }

    private function getQueryParameters(array $batchedRequest): array
    {
        $parameters = [];
        $urlSections = explode(separator: '?', string: (string) $batchedRequest['relative_url']);
        if (2 === count(value: $urlSections) && (isset($urlSections[1]) && '' !== $urlSections[1])) {
            $queryString = array_pop(array: $urlSections);
            parse_str(string: $queryString, result: $parameters);
        }

        return $parameters;
    }

    /**
     * @throws JsonException
     */
    private function getTransactions(Request $request): array
    {
        try {
            if (!empty($request->getContent())) {
                $requests = json_decode(
                    json: $request->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR
                );
            }
        } catch (JsonException $ex) {
            throw new HttpException(400, sprintf('Invalid request: %s', $ex->getMessage()));
        }
        if (empty($requests)) {
            throw new HttpException(400, 'Invalid request');
        }

        return array_map(callback: function ($batchedRequest) use ($request) {
            $files = $request->files->all();
            $requestFiles = array_map(fn ($file): string => trim($file), explode(',', $batchedRequest['attached_files'] ?? ''));
            $files = array_intersect_key($files, array_flip($requestFiles));
            $server = $request->server->all();
            $server['IS_INTERNAL'] = true;
            $parameters = $this->getParameters($batchedRequest);
            $newRequest = Request::create(
                uri: $batchedRequest['relative_url'],
                method: $batchedRequest['method'] ?? 'GET',
                parameters: $parameters,
                cookies: $request->cookies->all(),
                files: $files,
                server: $server,
                content: json_encode(value: $parameters, flags: JSON_THROW_ON_ERROR)
            );
            if ($request->hasSession()) {
                $newRequest->setSession($request->getSession());
            }
            $newRequest->headers->replace(headers: $request->headers->all());

            return $newRequest;
        }, array: $requests);
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
}
