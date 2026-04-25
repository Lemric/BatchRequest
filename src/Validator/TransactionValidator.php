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
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Allowed characters in a header name per RFC 7230 §3.2.6 (tchar).
     */
    private const HEADER_NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /**
     * Patterns matching path-traversal / null-byte / CRLF / control characters.
     */
    private const PATH_TRAVERSAL_PATTERNS = [
        '/\.\./',
        '/\\\\/',          // backslash – Windows-style path traversal
        '/\x00/',          // NULL byte – path truncation in legacy layers
        '/[\r\n]/',        // CR/LF – HTTP smuggling / response splitting in URI
        '/%0[ad]/i',       // percent-encoded CR/LF
    ];

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
            if ('' === $nameStr || !preg_match(self::HEADER_NAME_PATTERN, $nameStr)) {
                throw ValidationException::invalidUrl(sprintf('Invalid header name: %s', $nameStr));
            }

            $values = is_array($value) ? $value : [$value];
            foreach ($values as $singleValue) {
                $singleString = (string) $singleValue;
                if (preg_match('/[\r\n\x00]/', $singleString)) {
                    throw ValidationException::invalidUrl(sprintf('Header "%s" contains forbidden control characters', $nameStr));
                }
            }
        }
    }

    private function validateMethod(string $method): void
    {
        $normalized = mb_strtoupper($method);

        if (!in_array($normalized, self::ALLOWED_METHODS, true)) {
            throw ValidationException::invalidMethod($method);
        }
    }

    private function validateUri(string $uri): void
    {
        if ('' === $uri) {
            throw ValidationException::invalidUrl('URI cannot be empty');
        }

        foreach (self::PATH_TRAVERSAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $uri)) {
                throw ValidationException::pathTraversal($uri);
            }
        }

        if (str_contains($uri, '://')) {
            throw ValidationException::invalidUrl('Absolute URLs are not allowed');
        }

        if (!str_starts_with($uri, '/')) {
            throw ValidationException::invalidUrl('URI must start with /');
        }

        if (str_starts_with($uri, '//') || str_starts_with($uri, '/\\')) {
            throw ValidationException::invalidUrl('Protocol-relative URIs are not allowed');
        }

        if (preg_match('/[<>"\']/', $uri)) {
            throw ValidationException::invalidUrl('URI contains potentially dangerous characters');
        }
    }
}
