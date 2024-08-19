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

use Exception;
use JsonException;
use ReflectionClass;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response, Session\SessionInterface};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use function array_map;
use function array_merge;
use function array_pop;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use const JSON_THROW_ON_ERROR;

final class Transaction {

    public const JSON_CONTENT_TYPE = 'application/json';

    public const JSON_WWW_FORM_URLENCODED = 'application/x-www-form-urlencoded';

    private readonly string $method;

    private string $uri = '/';

    private readonly array $parameters;

    private readonly string $content;

    private readonly array $files;

    private readonly ?SessionInterface $session;

    private readonly array $cookies;

    private array $server;

    private array $headers;

    public function __construct(readonly array $subRequest, readonly Request $request)
    {
        $this->headers = $request->headers->all();
        $this->session = $request->hasSession() ? $request->getSession() : null;
        $this->cookies = $request->cookies->all();
        $this->server = $request->server->all();

        $this->headers = array_merge_recursive($this->headers, $subRequest['headers'] ??= []);
        $requestFiles = array_map(fn ($file): string => trim($file), explode(',', $subRequest['attached_files'] ?? ''));
        $this->files = array_intersect_key($request->files->all(), array_flip($requestFiles));
        $this->server['IS_INTERNAL'] = true;
        $this->uri = $subRequest['relative_url'];
        $this->method = $subRequest['method'] ?? Request::METHOD_GET;
        $this->parameters = $this->parseRequestParameters($subRequest);
        $this->content = json_encode($this->parameters === [] ? $subRequest['body'] ?? [] : $this->parameters);
    }

    public function handle(HttpKernelInterface $httpKernel): JsonResponse|Response
    {
        try {
            return $httpKernel->handle(request: $this->getRequest(), type: HttpKernelInterface::SUB_REQUEST);
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
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getServer(): array
    {
        return $this->server;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function getRequest(): Request
    {
        $request = Request::create(
            uri: $this->uri,
            method: $this->method,
            parameters: $this->parameters,
            cookies: $this->cookies,
            files: $this->files,
            server: $this->server,
            content: $this->content,
        );
        if ($this->session instanceof SessionInterface) {
            $request->setSession($this->session);
        }

        $request->headers->replace(headers: $this->headers);

        return $request;
    }

    private function parseRequestParameters(array $request): array
    {
        return array_merge(
            $this->getPayloadParameters($request),
            $this->getQueryParameters($request)
        );
    }

    private function getQueryParameters(array $request): array
    {
        $urlSections = explode(separator: '?', string: (string) $request['relative_url']);
        if (2 === count(value: $urlSections) && (isset($urlSections[1]) && '' !== $urlSections[1])) {
            $queryString = array_pop(array: $urlSections);
            parse_str(string: $queryString, result: $parameters);

            return $parameters;
        }

        return [];
    }


    private function getPayloadParameters(array $request): array
    {
        $parameters = [];
        if (isset($request['body'], $request['content-type'])) {
            if (self::JSON_CONTENT_TYPE === $request['content-type']
                && is_array(value: $request['body'])
            ) {
                return $request['body'];
            }

            if (self::JSON_WWW_FORM_URLENCODED === $request['content-type']
                && is_string(value: $request['body'])
            ) {
                parse_str(string: $request['body'], result: $parameters);
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
}