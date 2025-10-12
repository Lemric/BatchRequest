<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */
declare(strict_types=1);

namespace Lemric\BatchRequest;

use Exception;
use ReflectionClass;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response, Session\SessionInterface};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_map;
use function explode;
use function json_encode;

final class Transaction
{
    private readonly string $content;

    private readonly array $cookies;

    private readonly array $files;

    private readonly string $method;

    private readonly array $parameters;

    private readonly ?SessionInterface $session;

    public const JSON_CONTENT_TYPE = 'application/json';

    public const JSON_WWW_FORM_URLENCODED = 'application/x-www-form-urlencoded';

    private array $headers;

    private array $server;

    private string $uri = '/';

    public function __construct(
        private readonly array $subRequest,
        private readonly Request $request,
        private readonly TransactionParameterParser $parameterParser,
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getParameters(): array
    {
        return $this->parameters;
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
            $request->setSession(session: $this->session);
        }

        $request->headers->replace(headers: $this->headers);

        return $request;
    }

    public function getServer(): array
    {
        return $this->server;
    }

    public function getSession(): ?SessionInterface
    {
        return $this->session;
    }

    public function getUri(): string
    {
        return $this->uri;
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
                'type' => new ReflectionClass($e)->getShortName(),
                'message' => $e->getMessage(),
            ]],
            $status,
        );
    }

    private function initializeCookies(): void
    {
        $this->cookies = $this->request->cookies->all();
    }

    private function initializeFiles(): void
    {
        $requestFiles = array_map(fn ($file): string => mb_trim($file), explode(',', $this->subRequest['attached_files'] ?? ''));
        $this->files = array_intersect_key($this->request->files->all(), array_flip($requestFiles));
    }

    private function initializeHeaders(): void
    {
        $this->headers = array_merge_recursive($this->request->headers->all(), $this->subRequest['headers'] ?? []);
    }

    private function initializeRequestDetails(): void
    {
        $this->uri = $this->subRequest['relative_url'] ?? '/';
        $this->method = $this->subRequest['method'] ?? Request::METHOD_GET;
    }

    private function initializeServer(): void
    {
        $this->server = $this->request->server->all();
        $this->server['IS_INTERNAL'] = true;
    }

    private function initializeSession(): void
    {
        $this->session = $this->request->hasSession() ? $this->request->getSession() : null;
    }
}
