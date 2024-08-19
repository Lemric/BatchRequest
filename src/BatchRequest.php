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
use Exception;
use JsonException;
use Symfony\Component\HttpFoundation\{HeaderBag, JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\{HttpKernelInterface};
use Symfony\Component\HttpKernel\Exception\HttpException;
use function array_map;
use function end;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

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

    private function getBatchRequestResponse(TransitionCollection $transitions): JsonResponse
    {
        return $this->generateBatchResponse($transitions->map(fn(Transaction $transition): ?Response => $transition->handle($this->httpKernel)));
    }

    private function getTransactions(Request $request): TransitionCollection
    {
        try {
            $content = $request->getContent();
            if (!empty($content)) {
                if (is_string($content) && is_array(json_decode(json: $content, associative: true)) && 0 == json_last_error()) {
                    $requests = new TransitionCollection(json_decode(
                        json: $content,
                        associative: true,
                        flags: JSON_THROW_ON_ERROR
                    ), $request);
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

        return $requests;
    }

    private function parseRequest(Request $request): JsonResponse
    {
        try {
            $transitions = $this->getTransactions($request);
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
