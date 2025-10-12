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

use Lemric\BatchRequest\Exception\ParseException;

/**
 * Tokenizes raw input into structured tokens.
 */
interface TokenizerInterface
{
    /**
     * Tokenizes raw content into an array of tokens.
     *
     * @param string $content Raw content to tokenize
     *
     * @return array<int, Token>
     *
     * @throws ParseException
     */
    public function tokenize(string $content): array;
}
