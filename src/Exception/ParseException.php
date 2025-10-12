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
 * Thrown when parsing fails.
 */
class ParseException extends BatchRequestException
{
    public static function invalidJson(string $message): self
    {
        return new self(sprintf('Invalid JSON: %s', $message));
    }

    public static function malformedRequest(string $reason): self
    {
        return new self(sprintf('Malformed batch request: %s', $reason));
    }

    public static function unexpectedToken(string $expected, string $actual, int $position): self
    {
        return new self(
            sprintf('Expected token "%s" but got "%s" at position %d', $expected, $actual, $position),
        );
    }
}
