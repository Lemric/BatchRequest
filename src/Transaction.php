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

use JsonException;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Immutable value object representing a single transaction.
 *
 * @psalm-immutable
 */
final readonly class Transaction implements TransactionInterface
{
    /**
     * @param array<string, string|array<string>> $headers
     * @param array<string, mixed>                $parameters
     * @param array<string, string>               $cookies
     * @param array<string, mixed>                $files
     * @param array<string, mixed>                $serverVariables
     */
    public function __construct(
        private string $method,
        private string $uri,
        private array $headers = [],
        private array $parameters = [],
        private string $content = '',
        private array $cookies = [],
        private array $files = [],
        private array $serverVariables = [],
    ) {
    }

    /**
     * Factory method from raw array data.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    public static function fromArray(array $data): self
    {
        $content = '';
        if (isset($data['body'])) {
            $content = is_string($data['body']) ? $data['body'] : json_encode($data['body'], JSON_THROW_ON_ERROR);
        }

        return new self(
            method: (string) ($data['method'] ?? 'GET'),
            uri: (string) ($data['relative_url'] ?? '/'),
            headers: (array) ($data['headers'] ?? []),
            parameters: (array) ($data['parameters'] ?? []),
            content: $content,
            cookies: (array) ($data['cookies'] ?? []),
            files: (array) ($data['files'] ?? []),
            serverVariables: (array) ($data['server'] ?? []),
        );
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

    public function getServerVariables(): array
    {
        return $this->serverVariables;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Creates a new Transaction with modified content.
     */
    public function withContent(string $content): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->parameters,
            $content,
            $this->cookies,
            $this->files,
            $this->serverVariables,
        );
    }

    /**
     * Creates a new Transaction with additional headers.
     *
     * @param array<string, string|array<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->method,
            $this->uri,
            array_merge($this->headers, $headers),
            $this->parameters,
            $this->content,
            $this->cookies,
            $this->files,
            $this->serverVariables,
        );
    }

    /**
     * Creates a new Transaction with modified method.
     */
    public function withMethod(string $method): self
    {
        return new self(
            $method,
            $this->uri,
            $this->headers,
            $this->parameters,
            $this->content,
            $this->cookies,
            $this->files,
            $this->serverVariables,
        );
    }

    /**
     * Creates a new Transaction with modified URI.
     */
    public function withUri(string $uri): self
    {
        return new self(
            $this->method,
            $uri,
            $this->headers,
            $this->parameters,
            $this->content,
            $this->cookies,
            $this->files,
            $this->serverVariables,
        );
    }
}
