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

use Error;
use ReflectionException;
use function array_map;
use function end;
use Exception;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use JsonException;
use ReflectionClass;
use Symfony\Component\HttpFoundation\{HeaderBag, JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\{Exception\NotFoundHttpException, HttpKernelInterface};

final class BatchRequest
{
    private bool $includeHeaders = false;

    public function __construct(private readonly HttpKernelInterface $httpKernel)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $includeHeaders = $request->request->get('include_headers') ?? $request->query->get('include_headers');
        $this->includeHeaders = ($includeHeaders === 'true');

        return $this->parseRequest($request);
    }

    private function generateBatchResponse(array $responseList): JsonResponse
    {
        $jsonResponse = new JsonResponse();
        $jsonResponse->headers->set('Content-Type', Transaction::JSON_CONTENT_TYPE);
        $contentForSubResponses = [];

        foreach ($responseList as $key => $value) {
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

            $contentForSubResponses[$key] = [
                'code' => 0 === $value->getStatusCode() ? Response::HTTP_OK : $value->getStatusCode(),
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

    /**
     * @throws ReflectionException
     */
    private function getBatchRequestResponse(array $transitions): JsonResponse
    {
        return $this->generateBatchResponse(array_map(callback: function (Transaction $transition): ?Response {
            try {
                return $this->httpKernel->handle(request: $transition->getRequest(), type: HttpKernelInterface::SUB_REQUEST);
            } catch (NotFoundHttpException $e) {
                return new JsonResponse(data: [
                    'error' => [
                        'type' => (new ReflectionClass($e))->getShortName(),
                        'message' => $e->getMessage(),
                    ],
                ], status: Response::HTTP_NOT_FOUND);
            } catch (Exception $e) {
                return new JsonResponse(data: [
                    'error' => [
                        'type' => (new ReflectionClass($e))->getShortName(),
                        'message' => $e->getMessage(),
                    ],
                ], status: Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }, array: $transitions));
    }

    private function getTransactions(Request $request): array
    {
        try {
            $content = $request->getContent();
            if (!empty($content)) {
                if (is_string($content) && is_array(json_decode(json: $content, associative: true)) && 0 == json_last_error()) {
                    $requests = json_decode(
                        json: $content,
                        associative: true,
                        flags: JSON_THROW_ON_ERROR
                    );
                } else {
                    throw new HttpException(Response::HTTP_BAD_REQUEST, 'Invalid request: json decode exception');
                }
            }
        } catch (JsonException $jsonException) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, sprintf('Invalid request: %s', $jsonException->getMessage()));
        }


        if (empty($requests)) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'Invalid request');
        }


        $files = $request->files->all();
        $headers = $request->headers->all();
        $session = $request->hasSession() ? $request->getSession() : null;
        $cookies = $request->cookies->all();
        $server = $request->server->all();

        return array_map(callback: fn($request): Transaction => new Transaction($request, $headers, $session, $cookies, $server, $files), array: $requests);
    }

    private function parseRequest(Request $request): JsonResponse
    {
        $transitions = $this->getTransactions($request);
        try {
            return $this->getBatchRequestResponse($transitions);
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
            ], status: Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
