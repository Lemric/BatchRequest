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
use function array_merge;
use function array_map;
use function explode;
use function json_decode;
use function json_encode;
use function parse_str;
use const JSON_THROW_ON_ERROR;

final class Transaction
{
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

    public function __construct(
        private readonly array $subRequest,
        private readonly Request $request,
        private readonly TransactionParameterParser $parameterParser
    ) {
        $this->initializeHeaders();
        $this->initializeSession();
        $this->initializeCookies();
        $this->initializeServer();
        $this->initializeFiles();
        $this->initializeRequestDetails();
        $this->parameters = $this->parameterParser->parse($this->subRequest);
        $this->content = json_encode($this->parameters ?: ($this->subRequest['body'] ?? []));
    }

    private function initializeHeaders(): void
    {
        $this->headers = array_merge_recursive($this->request->headers->all(), $this->subRequest['headers'] ?? []);
    }

    private function initializeSession(): void
    {
        $this->session = $this->request->hasSession() ? $this->request->getSession() : null;
    }

    private function initializeCookies(): void
    {
        $this->cookies = $this->request->cookies->all();
    }

    private function initializeServer(): void
    {
        $this->server = $this->request->server->all();
        $this->server['IS_INTERNAL'] = true;
    }

    private function initializeFiles(): void
    {
        $requestFiles = array_map(fn($file): string => trim($file), explode(',', $this->subRequest['attached_files'] ?? ''));
        $this->files = array_intersect_key($this->request->files->all(), array_flip($requestFiles));
    }

    private function initializeRequestDetails(): void
    {
        $this->uri = $this->subRequest['relative_url'] ?? '/';
        $this->method = $this->subRequest['method'] ?? Request::METHOD_GET;
    }

    public function handle(HttpKernelInterface $httpKernel): Response
    {
        try {
            return $httpKernel->handle(request: $this->getRequest(), type: HttpKernelInterface::SUB_REQUEST);
        } catch (NotFoundHttpException $e) {
            return $this->createErrorResponse($e, Response::HTTP_NOT_FOUND);
        } catch (Exception $e) {
            return $this->createErrorResponse($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function createErrorResponse(Exception $e, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => [
                'type' => (new ReflectionClass($e))->getShortName(),
                'message' => $e->getMessage(),
            ]],
            $status
        );
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
            content: $this->content
        );

        if ($this->session instanceof SessionInterface) {
            $request->setSession(session: $this->session);
        }

        $request->headers->replace(headers: $this->headers);

        return $request;
    }

    public function getMethod(): string { return $this->method; }

    public function getUri(): string { return $this->uri; }

    public function getContent(): string { return $this->content; }

    public function getFiles(): array { return $this->files; }

    public function getHeaders(): array { return $this->headers; }

    public function getCookies(): array { return $this->cookies; }

    public function getServer(): array { return $this->server; }

    public function getParameters(): array { return $this->parameters; }

    public function getSession(): ?SessionInterface { return $this->session; }
}
