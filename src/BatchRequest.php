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

use ReflectionClass;

use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response, Session\SessionInterface};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\{Exception\NotFoundHttpException, HttpKernelInterface};

final class BatchRequest
{
    private bool $includeHeaders = false;

    public function __construct(private readonly HttpKernelInterface $httpKernel)
    {
    }

    public function handle(Request $request = null): JsonResponse
    {
        $this->includeHeaders = (
            ($request->request->has('include_headers')
                && 'false' != $request->request->has('include_headers'))
            || 'true' === $request->request->get('include_headers')
            || ($request->query->has('include_headers')
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

            $contentForSubResponses[$key] = [
                'code' => 0 === $value->getStatusCode() ? 200 : $value->getStatusCode(),
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
        return $this->generateBatchResponseFromSubResponses(array_map(callback: function (Request $request): ?Response {
            try {
                return $this->httpKernel->handle(request: $request, type: HttpKernelInterface::SUB_REQUEST);
            } catch (NotFoundHttpException $e) {
                return new JsonResponse(data: [
                    'error' => [
                        'type' => (new ReflectionClass($e))->getShortName(),
                        'message' => $e->getMessage(),
                    ],
                ], status: 404);
            } catch (Exception $e) {
                return new JsonResponse(data: [
                    'error' => [
                        'type' => (new ReflectionClass($e))->getShortName(),
                        'message' => $e->getMessage(),
                    ],
                ], status: 500);
            }
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

    private function getTransactions(Request $request): array
    {
        try {
            $content = $request->getContent();
            if (!empty($content)) {
                if (is_string($content) && is_array(json_decode($content, true)) && 0 == json_last_error()) {
                    $requests = json_decode(
                        json: $request->getContent(),
                        associative: true,
                        flags: JSON_THROW_ON_ERROR
                    );
                } else {
                    throw new HttpException(400, 'Invalid request: json decode exception');
                }
            }
        } catch (JsonException $ex) {
            throw new HttpException(400, sprintf('Invalid request: %s', $ex->getMessage()));
        }
        if (empty($requests)) {
            throw new HttpException(400, 'Invalid request');
        }
        $files = $request->files->all();
        $headers = $request->headers->all();
        $session = $request->hasSession() ? $request->getSession() : null;
        $cookies = $request->cookies->all();
        $server = $request->server->all();

        return array_map(callback: function ($batchedRequest) use ($files, $headers, $session, $cookies, $server) {

            $requestFiles = array_map(fn ($file): string => trim($file), explode(',', $batchedRequest['attached_files'] ?? ''));
            $files = array_intersect_key($files, array_flip($requestFiles));
            $server['IS_INTERNAL'] = true;
            $parameters = $this->getParameters($batchedRequest);
            $newRequest = Request::create(
                uri: $batchedRequest['relative_url'],
                method: $batchedRequest['method'] ?? 'GET',
                parameters: $parameters,
                cookies: $cookies,
                files: $files,
                server: $server,
                content: json_encode($parameters === [] ? $batchedRequest['body'] ?? [] : $parameters),
            );
            if ($session instanceof SessionInterface) {
                $newRequest->setSession($session);
            }
            $newRequest->headers->replace(headers: $headers);

            return $newRequest;
        }, array: $requests);
    }

    private function parseRequest(Request $request): JsonResponse
    {
        try {
            return $this->getBatchRequestResponse($this->getTransactions($request));
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
    }
}
