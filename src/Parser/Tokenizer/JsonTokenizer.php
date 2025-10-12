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

namespace Lemric\BatchRequest\Parser\Tokenizer;

use Lemric\BatchRequest\Exception\ParseException;
use Lemric\BatchRequest\Parser\{Token, TokenType, TokenizerInterface};

use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;

use const JSON_ERROR_NONE;

/**
 * Tokenizes JSON content into structured tokens for secure parsing.
 *
 * This tokenizer provides an additional layer of validation and security
 * by breaking down JSON into individual tokens before constructing objects.
 */
final readonly class JsonTokenizer implements TokenizerInterface
{
    private const MAX_DEPTH = 512;

    public function tokenize(string $content): array
    {
        if ('' === mb_trim($content)) {
            throw ParseException::malformedRequest('Empty content');
        }

        $decoded = json_decode($content, true, self::MAX_DEPTH);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw ParseException::invalidJson(json_last_error_msg());
        }

        return $this->tokenizeValue($decoded, 0);
    }

    /**
     * Checks if an array is associative.
     *
     * @param array<mixed> $arr
     */
    private function isAssociative(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Recursively tokenizes a decoded JSON value.
     *
     * @return array<int, Token>
     */
    private function tokenizeValue(mixed $value, int $position): array
    {
        $tokens = [];

        if (is_array($value)) {
            $tokens[] = $this->isAssociative($value)
                ? new Token(TokenType::OBJECT_START, null, $position)
                : new Token(TokenType::ARRAY_START, null, $position);

            $currentPos = $position + 1;
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $tokens[] = new Token(TokenType::KEY, $key, $currentPos++);
                }

                $tokens = [...$tokens, ...$this->tokenizeValue($item, $currentPos)];
                $currentPos += count($this->tokenizeValue($item, 0));
            }

            $tokens[] = $this->isAssociative($value)
                ? new Token(TokenType::OBJECT_END, null, $currentPos)
                : new Token(TokenType::ARRAY_END, null, $currentPos);
        } elseif (is_string($value)) {
            $tokens[] = new Token(TokenType::STRING, $value, $position);
        } elseif (is_int($value) || is_float($value)) {
            $tokens[] = new Token(TokenType::NUMBER, $value, $position);
        } elseif (is_bool($value)) {
            $tokens[] = new Token(TokenType::BOOLEAN, $value, $position);
        } elseif (null === $value) {
            $tokens[] = new Token(TokenType::NULL, null, $position);
        }

        return $tokens;
    }
}
