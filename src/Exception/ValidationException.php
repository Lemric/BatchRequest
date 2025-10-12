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

namespace Lemric\BatchRequest\Exception;

/**
 * Thrown when validation fails (OWASP).
 */
class ValidationException extends BatchRequestException
{
    /**
     * @param array<string, string> $violations
     */
    public function __construct(
        string $message,
        private readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    public static function batchSizeExceeded(int $size, int $limit): self
    {
        return new self(
            sprintf('Batch size %d exceeds limit of %d', $size, $limit),
            ['size' => (string) $size, 'limit' => (string) $limit],
        );
    }

    /**
     * @return array<string, string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public static function invalidMethod(string $method): self
    {
        return new self(sprintf('Invalid HTTP method: %s', $method), ['method' => $method]);
    }

    public static function invalidUrl(string $url): self
    {
        return new self(sprintf('Invalid or potentially unsafe URL: %s', $url), ['url' => $url]);
    }

    public static function pathTraversal(string $path): self
    {
        return new self(sprintf('Path traversal detected: %s', $path), ['path' => $path]);
    }
}
