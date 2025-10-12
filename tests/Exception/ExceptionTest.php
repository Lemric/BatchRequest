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

namespace Lemric\BatchRequest\Tests\Exception;

use Lemric\BatchRequest\Exception\{
    BatchRequestException,
    ExecutionException,
    ParseException,
    RateLimitException,
    ValidationException
};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionTest extends TestCase
{
    public function testBatchRequestExceptionIsRuntimeException(): void
    {
        $exception = new BatchRequestException('Test message');

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
    }

    public function testValidationExceptionBatchSizeExceeded(): void
    {
        $exception = ValidationException::batchSizeExceeded(100, 50);

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertStringContainsString('Batch size 100 exceeds limit of 50', $exception->getMessage());

        $violations = $exception->getViolations();
        $this->assertSame('100', $violations['size']);
        $this->assertSame('50', $violations['limit']);
    }

    public function testValidationExceptionInvalidMethod(): void
    {
        $exception = ValidationException::invalidMethod('INVALID');

        $this->assertStringContainsString('Invalid HTTP method: INVALID', $exception->getMessage());

        $violations = $exception->getViolations();
        $this->assertSame('INVALID', $violations['method']);
    }

    public function testValidationExceptionInvalidUrl(): void
    {
        $exception = ValidationException::invalidUrl('http://evil.com');

        $this->assertStringContainsString('Invalid or potentially unsafe URL', $exception->getMessage());

        $violations = $exception->getViolations();
        $this->assertSame('http://evil.com', $violations['url']);
    }

    public function testValidationExceptionPathTraversal(): void
    {
        $exception = ValidationException::pathTraversal('../etc/passwd');

        $this->assertStringContainsString('Path traversal detected', $exception->getMessage());

        $violations = $exception->getViolations();
        $this->assertSame('../etc/passwd', $violations['path']);
    }

    public function testValidationExceptionGetViolations(): void
    {
        $violations = ['field1' => 'error1', 'field2' => 'error2'];
        $exception = new ValidationException('Test', $violations);

        $this->assertSame($violations, $exception->getViolations());
    }

    public function testValidationExceptionEmptyViolations(): void
    {
        $exception = new ValidationException('Test');

        $this->assertSame([], $exception->getViolations());
    }

    public function testParseExceptionInvalidJson(): void
    {
        $exception = ParseException::invalidJson('Syntax error');

        $this->assertInstanceOf(ParseException::class, $exception);
        $this->assertStringContainsString('Invalid JSON: Syntax error', $exception->getMessage());
    }

    public function testParseExceptionMalformedRequest(): void
    {
        $exception = ParseException::malformedRequest('Missing required field');

        $this->assertStringContainsString('Malformed batch request: Missing required field', $exception->getMessage());
    }


    public function testExecutionExceptionTransactionFailed(): void
    {
        $exception = ExecutionException::transactionFailed(5, 'Database connection lost');

        $this->assertInstanceOf(ExecutionException::class, $exception);
        $this->assertStringContainsString('Transaction 5 failed', $exception->getMessage());
        $this->assertStringContainsString('Database connection lost', $exception->getMessage());
    }

    public function testRateLimitExceptionDefault(): void
    {
        $exception = new RateLimitException();

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertSame('Rate limit exceeded', $exception->getMessage());
        $this->assertNull($exception->getRetryAfter());
    }

    public function testRateLimitExceptionWithRetryAfter(): void
    {
        $retryAfter = time() + 3600;
        $exception = new RateLimitException('Too many requests', $retryAfter);

        $this->assertSame('Too many requests', $exception->getMessage());
        $this->assertSame($retryAfter, $exception->getRetryAfter());
    }

    public function testRateLimitExceptionGetRetryAfter(): void
    {
        $exception = new RateLimitException('Test', 12345);

        $this->assertSame(12345, $exception->getRetryAfter());
    }

    public function testExceptionsInheritance(): void
    {
        $this->assertInstanceOf(BatchRequestException::class, new ValidationException('test'));
        $this->assertInstanceOf(BatchRequestException::class, new ParseException('test'));
        $this->assertInstanceOf(BatchRequestException::class, new ExecutionException('test'));
        $this->assertInstanceOf(BatchRequestException::class, new RateLimitException('test'));
    }
}