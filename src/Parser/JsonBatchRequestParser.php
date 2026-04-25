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

namespace Lemric\BatchRequest\Parser;

use JsonException;
use Lemric\BatchRequest\{BatchRequestInterface, Transaction};
use Lemric\BatchRequest\Exception\ParseException;
use Lemric\BatchRequest\Model\BatchRequest;
use function array_map;
use function explode;
use function in_array;
use function is_array;
use function json_decode;
use function parse_str;
use function str_contains;
use const JSON_THROW_ON_ERROR;

/**
 * Parses JSON batch requests efficiently without unnecessary tokenization.
 */
final readonly class JsonBatchRequestParser implements ParserInterface
{
    /**
     * Maximum JSON payload size in bytes (5 MiB).
     */
    private const DEFAULT_MAX_CONTENT_LENGTH = 5 * 1024 * 1024;

    /**
     * Maximum JSON nesting depth.
     */
    private const MAX_JSON_DEPTH = 32;

    /**
     * Headers that MUST NOT be forwarded from the parent request to sub-requests.
     */
    private const SENSITIVE_CONTEXT_HEADERS = [
        'host',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
        'x-real-ip',
        'forwarded',
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    public function __construct(
        private int $maxContentLength = self::DEFAULT_MAX_CONTENT_LENGTH,
    ) {
    }

    public function parse(string $content, array $context = []): BatchRequestInterface
    {
        if ('' === $content) {
            throw ParseException::malformedRequest('Empty content');
        }

        if (mb_strlen($content) > $this->maxContentLength) {
            throw ParseException::malformedRequest(sprintf('Payload exceeds %d bytes', $this->maxContentLength));
        }

        try {
            $data = json_decode($content, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ParseException::invalidJson($e->getMessage());
        }

        if (!is_array($data)) {
            throw ParseException::malformedRequest('Root element must be an array');
        }

        $transactions = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                /* @var array<string, mixed> $item */
                $transactions[] = $this->parseTransaction($item, $context);
            }
        }

        return new BatchRequest(
            transactions: $transactions,
            includeHeaders: (bool) ($context['include_headers'] ?? false),
            clientIdentifier: (string) ($context['client_identifier'] ?? ''),
            metadata: $context,
        );
    }

    public function supports(string $contentType): bool
    {
        return in_array($contentType, ['application/json', 'text/json'], true);
    }

    /**
     * Extracts files based on attached_files directive.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function extractFiles(array $data, array $context): array
    {
        if (!isset($data['attached_files'])) {
            return [];
        }

        $attachedFileNames = array_map(
            'trim',
            explode(',', (string) $data['attached_files']),
        );

        $allFiles = (array) ($context['files'] ?? []);

        $result = [];
        foreach ($allFiles as $key => $value) {
            if (in_array((string) $key, $attachedFileNames, true)) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }

    /**
     * Extracts and merges parameters from body and query string.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function extractParameters(array $data): array
    {
        $parameters = [];

        if (isset($data['body'])) {
            if (is_array($data['body'])) {
                $parameters = $data['body'];
            } elseif (is_string($data['body']) && '' !== $data['body']) {
                $body = $data['body'];
                $trimmed = mb_ltrim($body);
                if ('' !== $trimmed && '{' !== $trimmed[0] && '[' !== $trimmed[0]) {
                    parse_str($body, $parsed);
                    $parameters = $parsed;
                }
            }
        }

        $queryParams = $this->extractQueryParameters($data);

        $result = [];
        foreach ($queryParams as $key => $value) {
            $result[(string) $key] = $value;
        }
        foreach ($parameters as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * Extracts query parameters from relative_url.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function extractQueryParameters(array $data): array
    {
        $url = (string) ($data['relative_url'] ?? '');
        if (!str_contains($url, '?')) {
            return [];
        }

        [$path, $queryString] = explode('?', $url, 2);
        parse_str($queryString, $parameters);

        $result = [];
        foreach ($parameters as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * Extracts server variables from context.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function extractServerVariables(array $context): array
    {
        $server = (array) ($context['server'] ?? []);
        $server['IS_INTERNAL'] = true;

        return $server;
    }

    /**
     * Extracts the URI path without query string.
     *
     * @param array<string, mixed> $data
     */
    private function extractUri(array $data): string
    {
        $url = (string) ($data['relative_url'] ?? '/');

        if (str_contains($url, '?')) {
            return explode('?', $url, 2)[0];
        }

        return $url;
    }

    /**
     * Merges headers from transaction and context.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @return array<string, string|array<string>>
     */
    private function mergeHeaders(array $data, array $context): array
    {
        $headers = (array) ($context['headers'] ?? []);
        $transactionHeaders = (array) ($data['headers'] ?? []);

        $result = [];
        foreach ($headers as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::SENSITIVE_CONTEXT_HEADERS, true)) {
                continue;
            }
            if (is_array($value)) {
                $result[(string) $key] = $value;
            } else {
                $result[(string) $key] = (string) $value;
            }
        }
        foreach ($transactionHeaders as $key => $value) {
            if (is_array($value)) {
                $result[(string) $key] = $value;
            } else {
                $result[(string) $key] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * Parses a single transaction from raw data.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function parseTransaction(array $data, array $context): Transaction
    {
        $parameters = $this->extractParameters($data);
        $content = $this->prepareContent($data, $parameters);

        $cookies = [];
        foreach ((array) ($context['cookies'] ?? []) as $key => $value) {
            $cookies[(string) $key] = (string) $value;
        }

        return new Transaction(
            method: (string) ($data['method'] ?? 'GET'),
            uri: $this->extractUri($data),
            headers: $this->mergeHeaders($data, $context),
            parameters: $parameters,
            content: $content,
            cookies: $cookies,
            files: $this->extractFiles($data, $context),
            serverVariables: $this->extractServerVariables($context),
        );
    }

    /**
     * Prepares content string from parameters or body.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $parameters
     */
    private function prepareContent(array $data, array $parameters): string
    {
        if (isset($data['body']) && is_string($data['body'])) {
            return $data['body'];
        }

        if ([] !== $parameters) {
            $encoded = json_encode($parameters);

            return false !== $encoded ? $encoded : '';
        }

        return '';
    }
}
