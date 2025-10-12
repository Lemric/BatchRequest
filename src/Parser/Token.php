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

/**
 * Represents a single token from the tokenizer.
 *
 * @psalm-immutable
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public mixed $value,
        public int $position,
    ) {
    }
}
