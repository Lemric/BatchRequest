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

namespace Lemric\BatchRequest\Validator;

use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\TransactionInterface;

/**
 * Validates individual transactions for security threats (OWASP).
 */
final readonly class TransactionValidator implements TransactionValidatorInterface
{
    /**
     * O(1) lookup map of accepted HTTP methods.
     */
    private const ALLOWED_METHODS = [
        'GET' => true,
        'POST' => true,
        'PUT' => true,
        'PATCH' => true,
        'DELETE' => true,
        'HEAD' => true,
        'OPTIONS' => true,
    ];

    /**
     * Allowed characters in a header name per RFC 7230 §3.2.6 (tchar).
     */
    private const HEADER_NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /**
     * Single combined regex for path-traversal / null-byte / CRLF /
     * percent-encoded CR-LF detection.
     */
    private const PATH_TRAVERSAL_REGEX = '/(?:\.\.|\\\\|\x00|[\r\n]|%0[ad])/i';

    /**
     * Forbidden control characters in header values (RFC 7230 §3.2.4).
     */
    private const HEADER_VALUE_CONTROL_REGEX = '/[\r\n\x00]/';

    /**
     * Dangerous characters in URI (XSS / template injection vectors).
     */
    private const URI_DANGEROUS_CHARS_REGEX = '/[<>"\']/';

    /**
     * Maximum percent-decode iterations to defeat double-encoding.
     */
    private const MAX_DECODE_ITERATIONS = 2;

    public function validate(TransactionInterface $transaction): void
    {
        $this->validateMethod($transaction->getMethod());
        $this->validateUri($transaction->getUri());
        $this->validateHeaders($transaction->getHeaders());
    }

    /**
     * HTTP Response Splitting and Header Injection. The validator must reject them.
     *
     * @param array<string, string|array<string>> $headers
     */
    private function validateHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $nameStr = (string) $name;
            if ('' === $nameStr || 1 !== preg_match(self::HEADER_NAME_PATTERN, $nameStr)) {
                throw ValidationException::invalidUrl(sprintf('Invalid header name: %s', $nameStr));
            }

            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    if (1 === preg_match(self::HEADER_VALUE_CONTROL_REGEX, (string) $singleValue)) {
                        throw ValidationException::invalidUrl(sprintf('Header "%s" contains forbidden control characters', $nameStr));
                    }
                }

                continue;
            }

            if (1 === preg_match(self::HEADER_VALUE_CONTROL_REGEX, (string) $value)) {
                throw ValidationException::invalidUrl(sprintf('Header "%s" contains forbidden control characters', $nameStr));
            }
        }
    }

    private function validateMethod(string $method): void
    {
        if (!isset(self::ALLOWED_METHODS[strtoupper($method)])) {
            throw ValidationException::invalidMethod($method);
        }
    }

    private function validateUri(string $uri): void
    {
        if ('' === $uri) {
            throw ValidationException::invalidUrl('URI cannot be empty');
        }

        // Defeat double / triple percent-encoding: decode iteratively
        // until the string stabilises (bounded by MAX_DECODE_ITERATIONS).
        $decoded = $uri;
        for ($i = 0; $i < self::MAX_DECODE_ITERATIONS; ++$i) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (1 === preg_match(self::PATH_TRAVERSAL_REGEX, $uri)
            || 1 === preg_match(self::PATH_TRAVERSAL_REGEX, $decoded)
        ) {
            throw ValidationException::pathTraversal($uri);
        }

        if (str_contains($uri, '://') || str_contains($decoded, '://')) {
            throw ValidationException::invalidUrl('Absolute URLs are not allowed');
        }

        if (!str_starts_with($uri, '/')) {
            throw ValidationException::invalidUrl('URI must start with /');
        }

        if (str_starts_with($uri, '//') || str_starts_with($uri, '/\\')) {
            throw ValidationException::invalidUrl('Protocol-relative URIs are not allowed');
        }

        if (1 === preg_match(self::URI_DANGEROUS_CHARS_REGEX, $decoded)) {
            throw ValidationException::invalidUrl('URI contains potentially dangerous characters');
        }
    }
}
