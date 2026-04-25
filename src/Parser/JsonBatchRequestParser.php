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

use Generator;
use JsonException;
use Lemric\BatchRequest\{BatchRequestInterface, Transaction};
use Lemric\BatchRequest\Exception\ParseException;
use Lemric\BatchRequest\Model\BatchRequest;
use function explode;
use function is_array;
use function is_string;
use function iterator_to_array;
use function json_decode;
use function parse_str;
use function str_contains;
use function strlen;
use function strtolower;
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
     * Maximum size in bytes of a single transaction body (256 KiB).
     */
    private const DEFAULT_MAX_TRANSACTION_CONTENT_LENGTH = 262144;

    /**
     * Maximum JSON nesting depth.
     */
    private const MAX_JSON_DEPTH = 32;

    /**
     * Maximum number of fields produced by parse_str (defeats array bombs).
     */
    private const MAX_PARSE_STR_FIELDS = 1000;

    /**
     * Headers that MUST NOT be forwarded from the parent request to
     * sub-requests under any circumstance (security-critical).
     *
     * Stored as a flipped map for O(1) `isset` lookup; lookups are
     * always performed against `strtolower($name)`.
     *
     * @var array<string, true>
     */
    private const SENSITIVE_CONTEXT_HEADERS = [
        'host' => true,
        'x-forwarded-for' => true,
        'x-forwarded-host' => true,
        'x-forwarded-proto' => true,
        'x-forwarded-port' => true,
        'x-real-ip' => true,
        'forwarded' => true,
        'connection' => true,
        'keep-alive' => true,
        'proxy-authenticate' => true,
        'proxy-authorization' => true,
        'te' => true,
        'trailer' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
        // Authentication/session: never inherit from parent — sub-requests
        // declare their own credentials via the top-level `authorization`
        // payload field or per-transaction `headers`.
        'cookie' => true,
        'set-cookie' => true,
        'authorization' => true,
        'x-csrf-token' => true,
    ];

    /**
     * Default whitelist of parent-request headers that may be safely
     * propagated into sub-requests when the caller does not override.
     *
     * @var array<string, true>
     */
    private const DEFAULT_FORWARDED_HEADERS = [
        'accept' => true,
        'accept-language' => true,
        'content-type' => true,
        'user-agent' => true,
    ];

    /**
     * Whitelist of $_SERVER keys safe to propagate to a sub-request.
     * `HTTP_*` entries are intentionally excluded — they would
     * shadow/duplicate header forwarding and may carry secrets.
     *
     * @var array<string, true>
     */
    private const ALLOWED_SERVER_VARS = [
        'REMOTE_ADDR' => true,
        'REMOTE_PORT' => true,
        'SERVER_NAME' => true,
        'SERVER_PORT' => true,
        'SERVER_PROTOCOL' => true,
        'REQUEST_METHOD' => true,
        'REQUEST_SCHEME' => true,
        'REQUEST_TIME' => true,
        'REQUEST_TIME_FLOAT' => true,
        'HTTPS' => true,
    ];

    /**
     * @var array<string, true>
     */
    private array $forwardedHeadersMap;

    /**
     * @param array<int, string> $forwardedHeadersWhitelist Lower-case header names allowed to flow from parent context to sub-requests.
     */
    public function __construct(
        private int $maxContentLength = self::DEFAULT_MAX_CONTENT_LENGTH,
        private int $maxTransactionContentLength = self::DEFAULT_MAX_TRANSACTION_CONTENT_LENGTH,
        array $forwardedHeadersWhitelist = [],
    ) {
        if ([] === $forwardedHeadersWhitelist) {
            $this->forwardedHeadersMap = self::DEFAULT_FORWARDED_HEADERS;

            return;
        }

        $map = [];
        foreach ($forwardedHeadersWhitelist as $name) {
            $key = strtolower((string) $name);
            if ('' === $key || isset(self::SENSITIVE_CONTEXT_HEADERS[$key])) {
                continue;
            }
            $map[$key] = true;
        }
        $this->forwardedHeadersMap = $map;
    }

    public function parse(string $content, array $context = []): BatchRequestInterface
    {
        if ('' === $content) {
            throw ParseException::malformedRequest('Empty content');
        }

        // Byte-accurate guard (mb_strlen counts characters, allowing
        // larger payloads through when multi-byte chars are present).
        if (strlen($content) > $this->maxContentLength) {
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

        $serverVars = $this->extractServerVariables($context);
        $cookies = $this->extractCookies($context);
        $contextHeaders = $this->extractContextHeaders($context);
        $contextFiles = (array) ($context['files'] ?? []);

        $transactions = iterator_to_array(
            $this->yieldTransactions($data, $contextHeaders, $cookies, $contextFiles, $serverVars),
            false,
        );

        return new BatchRequest(
            transactions: $transactions,
            includeHeaders: (bool) ($context['include_headers'] ?? false),
            clientIdentifier: (string) ($context['client_identifier'] ?? ''),
            metadata: $context,
        );
    }

    public function supports(string $contentType): bool
    {
        return 'application/json' === $contentType || 'text/json' === $contentType;
    }

    /**
     * @param array<int|string, mixed>            $data
     * @param array<string, string|array<string>> $contextHeaders
     * @param array<string, string>               $cookies
     * @param array<string, mixed>                $contextFiles
     * @param array<string, mixed>                $serverVars
     *
     * @return Generator<int, Transaction>
     */
    private function yieldTransactions(
        array $data,
        array $contextHeaders,
        array $cookies,
        array $contextFiles,
        array $serverVars,
    ): Generator {
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            /* @var array<string, mixed> $item */
            yield $this->parseTransaction($item, $contextHeaders, $cookies, $contextFiles, $serverVars);
        }
    }

    /**
     * Lower-cases and filters context headers through the configured
     * whitelist, dropping anything in the sensitive list. Returns an
     * array preserving the original (possibly mixed-case) header names
     * for the first occurrence of each lower-cased key.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, string|array<string>>
     */
    private function extractContextHeaders(array $context): array
    {
        $raw = (array) ($context['headers'] ?? []);
        if ([] === $raw) {
            return [];
        }

        $allowed = $this->forwardedHeadersMap;
        $sensitive = self::SENSITIVE_CONTEXT_HEADERS;

        $result = [];
        foreach ($raw as $key => $value) {
            $name = (string) $key;
            $lower = strtolower($name);
            if (isset($sensitive[$lower]) || !isset($allowed[$lower])) {
                continue;
            }

            if (is_array($value)) {
                $stringified = [];
                foreach ($value as $v) {
                    $stringified[] = (string) $v;
                }
                $result[$name] = $stringified;

                continue;
            }

            $result[$name] = (string) $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, string>
     */
    private function extractCookies(array $context): array
    {
        $raw = (array) ($context['cookies'] ?? []);
        if ([] === $raw) {
            return [];
        }

        $result = [];
        foreach ($raw as $key => $value) {
            $result[(string) $key] = (string) $value;
        }

        return $result;
    }

    /**
     * Extracts files based on attached_files directive.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $contextFiles
     *
     * @return array<string, mixed>
     */
    private function extractFiles(array $data, array $contextFiles): array
    {
        if (!isset($data['attached_files']) || [] === $contextFiles) {
            return [];
        }

        $names = explode(',', (string) $data['attached_files']);
        $whitelist = [];
        foreach ($names as $name) {
            $trimmed = trim($name);
            if ('' !== $trimmed) {
                $whitelist[$trimmed] = true;
            }
        }

        if ([] === $whitelist) {
            return [];
        }

        $result = [];
        foreach ($contextFiles as $key => $value) {
            $name = (string) $key;
            if (isset($whitelist[$name])) {
                $result[$name] = $value;
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
            $body = $data['body'];
            if (is_array($body)) {
                $parameters = $body;
            } elseif (is_string($body) && '' !== $body) {
                $trimmed = ltrim($body);
                if ('' !== $trimmed && '{' !== $trimmed[0] && '[' !== $trimmed[0]) {
                    $parsed = $this->safeParseStr($body);
                    $parameters = $parsed;
                }
            }
        }

        $queryParams = $this->extractQueryParameters($data);

        if ([] === $queryParams) {
            $result = [];
            foreach ($parameters as $key => $value) {
                $result[(string) $key] = $value;
            }

            return $result;
        }

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

        $queryString = explode('?', $url, 2)[1];
        $parameters = $this->safeParseStr($queryString);

        $result = [];
        foreach ($parameters as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * `parse_str` wrapper with a hard cap on field count to defeat
     * array-bomb DoS attempts.
     *
     * @return array<string, array<mixed>|string>
     */
    private function safeParseStr(string $input): array
    {
        parse_str($input, $parsed);

        if (count($parsed) > self::MAX_PARSE_STR_FIELDS) {
            throw ParseException::malformedRequest(
                sprintf('Too many parameters (limit: %d)', self::MAX_PARSE_STR_FIELDS),
            );
        }

        return $parsed;
    }

    /**
     * Extracts server variables from context using a strict whitelist.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function extractServerVariables(array $context): array
    {
        $raw = (array) ($context['server'] ?? []);
        $allowed = self::ALLOWED_SERVER_VARS;

        $server = ['IS_INTERNAL' => true];
        foreach ($raw as $key => $value) {
            $name = (string) $key;
            if (isset($allowed[$name])) {
                $server[$name] = $value;
            }
        }

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
     * Merges per-transaction headers over filtered context headers and
     * applies the optional top-level `authorization` field as a single
     * `Authorization: <value>` header (overrides everything for the
     * current transaction only).
     *
     * @param array<string, mixed>                $data
     * @param array<string, string|array<string>> $contextHeaders Already filtered through whitelist.
     *
     * @return array<string, string|array<string>>
     */
    private function mergeHeaders(array $data, array $contextHeaders): array
    {
        $result = $contextHeaders;

        $transactionHeaders = (array) ($data['headers'] ?? []);
        foreach ($transactionHeaders as $key => $value) {
            $name = (string) $key;
            if (is_array($value)) {
                $stringified = [];
                foreach ($value as $v) {
                    $stringified[] = (string) $v;
                }
                $result[$name] = $stringified;

                continue;
            }
            $result[$name] = (string) $value;
        }

        if (isset($data['authorization']) && is_string($data['authorization']) && '' !== $data['authorization']) {
            // Drop any case-variant of an existing Authorization header
            // before injecting the per-transaction credential.
            foreach ($result as $existing => $_) {
                if (0 === strcasecmp((string) $existing, 'Authorization')) {
                    unset($result[$existing]);
                }
            }
            $result['Authorization'] = $data['authorization'];
        }

        return $result;
    }

    /**
     * Parses a single transaction from raw data.
     *
     * @param array<string, mixed>                $data
     * @param array<string, string|array<string>> $contextHeaders
     * @param array<string, string>               $cookies
     * @param array<string, mixed>                $contextFiles
     * @param array<string, mixed>                $serverVars
     */
    private function parseTransaction(
        array $data,
        array $contextHeaders,
        array $cookies,
        array $contextFiles,
        array $serverVars,
    ): Transaction {
        $parameters = $this->extractParameters($data);
        $content = $this->prepareContent($data, $parameters);

        if (strlen($content) > $this->maxTransactionContentLength) {
            throw ParseException::malformedRequest(
                sprintf('Transaction body exceeds %d bytes', $this->maxTransactionContentLength),
            );
        }

        return new Transaction(
            method: (string) ($data['method'] ?? 'GET'),
            uri: $this->extractUri($data),
            headers: $this->mergeHeaders($data, $contextHeaders),
            parameters: $parameters,
            content: $content,
            cookies: $cookies,
            files: $this->extractFiles($data, $contextFiles),
            serverVariables: $serverVars,
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
