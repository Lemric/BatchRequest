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

    private const PATH_TRAVERSAL_PATTERNS = [
        '/\.\./',
        '/\/\./',
        '/\\\/',
        '/\0/',
    ];

    public function validate(TransactionInterface $transaction): void
    {
        $this->validateMethod($transaction->getMethod());
        $this->validateUri($transaction->getUri());
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

        if (preg_match('/[<>"\']/', $uri)) {
            throw ValidationException::invalidUrl('URI contains potentially dangerous characters');
        }
    }
}
