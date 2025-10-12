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

namespace Lemric\BatchRequest\Tests\Parser\Tokenizer;

use Lemric\BatchRequest\Exception\ParseException;
use Lemric\BatchRequest\Parser\Tokenizer\JsonTokenizer;
use Lemric\BatchRequest\Parser\TokenType;
use PHPUnit\Framework\TestCase;

final class JsonTokenizerTest extends TestCase
{
    private JsonTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new JsonTokenizer();
    }

    public function testTokenizeSimpleArray(): void
    {
        $content = json_encode([1, 2, 3]);
        $tokens = $this->tokenizer->tokenize($content);

        $this->assertNotEmpty($tokens);
        $this->assertSame(TokenType::ARRAY_START, $tokens[0]->type);
        $this->assertSame(TokenType::ARRAY_END, end($tokens)->type);
    }

    public function testTokenizeSimpleObject(): void
    {
        $content = json_encode(['key' => 'value']);
        $tokens = $this->tokenizer->tokenize($content);

        $this->assertNotEmpty($tokens);
        $this->assertSame(TokenType::OBJECT_START, $tokens[0]->type);
        $this->assertSame(TokenType::OBJECT_END, end($tokens)->type);
    }

    public function testTokenizeString(): void
    {
        $content = json_encode(['name' => 'John']);
        $tokens = $this->tokenizer->tokenize($content);

        $keyToken = null;
        $valueToken = null;

        foreach ($tokens as $token) {
            if (TokenType::KEY === $token->type && 'name' === $token->value) {
                $keyToken = $token;
            }
            if (TokenType::STRING === $token->type && 'John' === $token->value) {
                $valueToken = $token;
            }
        }

        $this->assertNotNull($keyToken);
        $this->assertNotNull($valueToken);
        $this->assertSame('name', $keyToken->value);
        $this->assertSame('John', $valueToken->value);
    }

    public function testTokenizeNumber(): void
    {
        $content = json_encode(['age' => 25]);
        $tokens = $this->tokenizer->tokenize($content);

        $numberToken = null;
        foreach ($tokens as $token) {
            if (TokenType::NUMBER === $token->type) {
                $numberToken = $token;
                break;
            }
        }

        $this->assertNotNull($numberToken);
        $this->assertSame(25, $numberToken->value);
    }

    public function testTokenizeBoolean(): void
    {
        $content = json_encode(['active' => true, 'deleted' => false]);
        $tokens = $this->tokenizer->tokenize($content);

        $booleanTokens = array_filter(
            $tokens,
            fn ($token) => TokenType::BOOLEAN === $token->type
        );

        $this->assertCount(2, $booleanTokens);
    }

    public function testTokenizeNull(): void
    {
        $content = json_encode(['value' => null]);
        $tokens = $this->tokenizer->tokenize($content);

        $nullToken = null;
        foreach ($tokens as $token) {
            if (TokenType::NULL === $token->type) {
                $nullToken = $token;
                break;
            }
        }

        $this->assertNotNull($nullToken);
        $this->assertNull($nullToken->value);
    }

    public function testTokenizeNestedStructure(): void
    {
        $content = json_encode([
            'user' => [
                'name' => 'John',
                'age' => 30,
            ],
        ]);

        $tokens = $this->tokenizer->tokenize($content);

        $objectStarts = array_filter(
            $tokens,
            fn ($token) => TokenType::OBJECT_START === $token->type
        );

        $this->assertGreaterThanOrEqual(2, count($objectStarts));
    }

    public function testTokenizeComplexBatchRequest(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
            ['method' => 'POST', 'relative_url' => '/api/users', 'body' => ['name' => 'John']],
        ]);

        $tokens = $this->tokenizer->tokenize($content);

        $this->assertNotEmpty($tokens);
        $this->assertSame(TokenType::ARRAY_START, $tokens[0]->type);
    }

    public function testTokenizeThrowsExceptionForInvalidJson(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->tokenizer->tokenize('invalid json');
    }

    public function testTokenizeThrowsExceptionForEmptyContent(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty content');

        $this->tokenizer->tokenize('');
    }

    public function testTokenizeThrowsExceptionForWhitespaceOnly(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty content');

        $this->tokenizer->tokenize('   ');
    }

    public function testTokenPositions(): void
    {
        $content = json_encode(['a', 'b', 'c']);
        $tokens = $this->tokenizer->tokenize($content);

        foreach ($tokens as $token) {
            $this->assertIsInt($token->position);
            $this->assertGreaterThanOrEqual(0, $token->position);
        }
    }

    public function testTokenizeMixedTypes(): void
    {
        $content = json_encode([
            'string' => 'value',
            'number' => 42,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'object' => ['nested' => 'value'],
        ]);

        $tokens = $this->tokenizer->tokenize($content);

        $types = array_map(fn ($token) => $token->type, $tokens);

        $this->assertContains(TokenType::STRING, $types);
        $this->assertContains(TokenType::NUMBER, $types);
        $this->assertContains(TokenType::BOOLEAN, $types);
        $this->assertContains(TokenType::NULL, $types);
        $this->assertContains(TokenType::ARRAY_START, $types);
        $this->assertContains(TokenType::OBJECT_START, $types);
    }

    public function testTokenizeLargeStructure(): void
    {
        $data = [];
        for ($i = 0; $i < 100; ++$i) {
            $data[] = [
                'id' => $i,
                'name' => "Item {$i}",
                'active' => 0 === $i % 2,
            ];
        }

        $content = json_encode($data);
        $tokens = $this->tokenizer->tokenize($content);

        $this->assertNotEmpty($tokens);
        $this->assertSame(TokenType::ARRAY_START, $tokens[0]->type);
    }

    public function testTokenizePreservesDataTypes(): void
    {
        $content = json_encode([
            'integer' => 42,
            'float' => 3.14159,
            'string' => 'text',
            'boolean' => true,
        ]);

        $tokens = $this->tokenizer->tokenize($content);

        $integerToken = null;
        $floatToken = null;
        $stringToken = null;
        $booleanToken = null;

        foreach ($tokens as $token) {
            if (TokenType::NUMBER === $token->type && 42 === $token->value) {
                $integerToken = $token;
            }
            if (TokenType::NUMBER === $token->type && is_float($token->value)) {
                $floatToken = $token;
            }
            if (TokenType::STRING === $token->type && 'text' === $token->value) {
                $stringToken = $token;
            }
            if (TokenType::BOOLEAN === $token->type) {
                $booleanToken = $token;
            }
        }

        $this->assertNotNull($integerToken);
        $this->assertIsInt($integerToken->value);
        $this->assertNotNull($floatToken);
        $this->assertIsFloat($floatToken->value);
        $this->assertNotNull($stringToken);
        $this->assertIsString($stringToken->value);
        $this->assertNotNull($booleanToken);
        $this->assertIsBool($booleanToken->value);
    }
}