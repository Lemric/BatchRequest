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

namespace Lemric\BatchRequest\Tests\Validator;

use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\Model\BatchRequest;
use Lemric\BatchRequest\Transaction;
use Lemric\BatchRequest\Validator\{BatchRequestValidator, TransactionValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionValidatorTest extends TestCase
{
    private TransactionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TransactionValidator();
    }

    public function testAcceptsSafeRelativeUri(): void
    {
        $this->validator->validate(new Transaction('GET', '/api/posts/1'));
        $this->assertTrue(true);
    }

    public function testRejectsEmptyUri(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction('GET', ''));
    }

    public function testRejectsAbsoluteUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Absolute URLs are not allowed');
        $this->validator->validate(new Transaction('GET', 'https://evil.example/api'));
    }

    public function testRejectsProtocolRelativeUri(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction('GET', '//evil.example/api'));
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction('GET', '/api/../etc/passwd'));
    }

    #[DataProvider('multiEncodedTraversalProvider')]
    public function testRejectsMultiEncodedPathTraversal(string $uri): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction('GET', $uri));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function multiEncodedTraversalProvider(): iterable
    {
        yield 'double-encoded dots' => ['/%252e%252e/etc/passwd'];
        yield 'triple-encoded dots' => ['/%25252e%25252e/etc/passwd'];
        yield 'encoded null byte' => ["/api%00/secret"];
        yield 'crlf injection' => ["/api%0d%0aX-Injected:%20yes"];
    }

    public function testRejectsHeaderCrLfInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction(
            'GET',
            '/api/posts',
            ['X-Custom' => "ok\r\nX-Evil: 1"],
        ));
    }

    public function testRejectsInvalidHeaderName(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction(
            'GET',
            '/api/posts',
            ["Bad Name" => 'value'],
        ));
    }

    public function testBatchValidatorRejectsRecursiveBatchViaIsInternal(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Recursive batch requests are not allowed');

        $batchValidator = new BatchRequestValidator($this->validator);
        $batch = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            metadata: ['server' => ['IS_INTERNAL' => true, 'REMOTE_ADDR' => '127.0.0.1']],
        );

        $batchValidator->validate($batch);
    }

    public function testBatchValidatorRejectsExplicitRecursiveFlag(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Recursive batch requests are not allowed');

        $batchValidator = new BatchRequestValidator($this->validator);
        $batch = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            metadata: ['is_recursive_batch' => true],
        );

        $batchValidator->validate($batch);
    }

    public function testBatchValidatorAllowsTopLevelRequestWithoutIsInternal(): void
    {
        $batchValidator = new BatchRequestValidator($this->validator);
        $batch = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            metadata: ['server' => ['REMOTE_ADDR' => '203.0.113.10']],
        );

        $batchValidator->validate($batch);
        $this->assertTrue(true);
    }

    #[DataProvider('allowedMethodsProvider')]
    public function testAcceptsAllAllowedMethods(string $method): void
    {
        $this->validator->validate(new Transaction($method, '/api/resource'));
        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowedMethodsProvider(): iterable
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'get', 'Post'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('rejectedMethodsProvider')]
    public function testRejectsDisallowedMethods(string $method): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid HTTP method');
        $this->validator->validate(new Transaction($method, '/api/resource'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedMethodsProvider(): iterable
    {
        yield 'TRACE' => ['TRACE'];
        yield 'CONNECT' => ['CONNECT'];
        yield 'empty' => [''];
    }

    public function testRejectsUriNotStartingWithSlash(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('URI must start with /');
        $this->validator->validate(new Transaction('GET', 'api/posts'));
    }

    public function testRejectsBackslashProtocolRelativeUri(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction('GET', '/\\evil.example/api'));
    }

    public function testRejectsDangerousCharactersInDecodedUri(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dangerous characters');
        $this->validator->validate(new Transaction('GET', '/api/<script>'));
    }

    public function testRejectsCrLfInArrayHeaderValues(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction(
            'GET',
            '/api/posts',
            ['Accept' => ["ok", "bad\nvalue"]],
        ));
    }

    public function testRejectsEmptyHeaderName(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(new Transaction(
            'GET',
            '/api/posts',
            ['' => 'value'],
        ));
    }

    public function testAcceptsHeaderArrayValuesWithoutControlChars(): void
    {
        $this->validator->validate(new Transaction(
            'GET',
            '/api/posts',
            ['Accept' => ['application/json', 'text/plain']],
        ));
        $this->assertTrue(true);
    }
}
