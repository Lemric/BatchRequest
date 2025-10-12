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

use Lemric\BatchRequest\BatchRequestInterface;
use Lemric\BatchRequest\Exception\ParseException;

/**
 * Parses raw input into a BatchRequest.
 */
interface ParserInterface
{
    /**
     * Parses raw content into a BatchRequest.
     *
     * @param string               $content Raw batch request content
     * @param array<string, mixed> $context Additional context (headers, client IP, etc.)
     *
     * @throws ParseException
     */
    public function parse(string $content, array $context = []): BatchRequestInterface;

    /**
     * Checks if this parser supports the given content type.
     */
    public function supports(string $contentType): bool;
}
