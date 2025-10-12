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
use Lemric\BatchRequest\Validator\BatchRequestValidator;
use Lemric\BatchRequest\Validator\TransactionValidator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private TransactionValidator $transactionValidator;

    private BatchRequestValidator $batchValidator;

    protected function setUp(): void
    {
        $this->transactionValidator = new TransactionValidator();
        $this->batchValidator = new BatchRequestValidator($this->transactionValidator, 5);
    }

    public function testTransactionValidatorAcceptsValidMethods(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

        foreach ($methods as $method) {
            $transaction = new Transaction($method, '/api/posts');
            $this->transactionValidator->validate($transaction);
            $this->assertTrue(true); // If no exception, validation passed
        }
    }

    public function testTransactionValidatorRejectsInvalidMethod(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid HTTP method: INVALID');

        $transaction = new Transaction('INVALID', '/api/posts');
        $this->transactionValidator->validate($transaction);
    }

    public function testTransactionValidatorRejectsEmptyUri(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('URI cannot be empty');

        $transaction = new Transaction('GET', '');
        $this->transactionValidator->validate($transaction);
    }

    public function testTransactionValidatorDetectsPathTraversal(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $transaction = new Transaction('GET', '/api/../etc/passwd');
        $this->transactionValidator->validate($transaction);
    }

    public function testTransactionValidatorRejectsAbsoluteUrls(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Absolute URLs are not allowed');

        $transaction = new Transaction('GET', 'http://example.com/api/posts');
        $this->transactionValidator->validate($transaction);
    }

    public function testTransactionValidatorRejectsUriNotStartingWithSlash(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('URI must start with /');

        $transaction = new Transaction('GET', 'api/posts');
        $this->transactionValidator->validate($transaction);
    }

    public function testTransactionValidatorRejectsDangerousCharacters(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('potentially dangerous characters');

        $transaction = new Transaction('GET', '/api/posts?name=<script>');
        $this->transactionValidator->validate($transaction);
    }

    public function testBatchValidatorRejectsEmptyBatch(): void
    {
        $this->expectException(ValidationException::class);

        $batchRequest = new BatchRequest([]);
        $this->batchValidator->validate($batchRequest);
    }

    public function testBatchValidatorRejectsBatchExceedingMaxSize(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Batch size 6 exceeds limit of 5');

        $transactions = [];
        for ($i = 0; $i < 6; ++$i) {
            $transactions[] = new Transaction('GET', '/api/posts');
        }

        $batchRequest = new BatchRequest($transactions);
        $this->batchValidator->validate($batchRequest);
    }

    public function testBatchValidatorAcceptsValidBatch(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ];

        $batchRequest = new BatchRequest($transactions);
        $this->batchValidator->validate($batchRequest);

        $this->assertTrue(true); // If no exception, validation passed
    }

    public function testBatchValidatorValidatesEachTransaction(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('INVALID', '/api/users'),
        ];

        $batchRequest = new BatchRequest($transactions);
        $this->batchValidator->validate($batchRequest);
    }

    /**
     * @dataProvider pathTraversalProvider
     */
    public function testPathTraversalDetection(string $uri): void
    {
        $this->expectException(ValidationException::class);

        $transaction = new Transaction('GET', $uri);
        $this->transactionValidator->validate($transaction);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function pathTraversalProvider(): array
    {
        return [
            'double dot' => ['/api/../etc/passwd'],
            'single dot' => ['/.'],
            'backslash' => ['/api\\users'],
            'null byte' => ["/api/posts\0"],
        ];
    }

    /**
     * @dataProvider validUriProvider
     */
    public function testValidUriAccepted(string $uri): void
    {
        $transaction = new Transaction('GET', $uri);
        $this->transactionValidator->validate($transaction);

        $this->assertTrue(true);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function validUriProvider(): array
    {
        return [
            'simple path' => ['/api/posts'],
            'nested path' => ['/api/v1/users/123'],
            'with query' => ['/api/posts?page=1&limit=10'],
            'root' => ['/'],
        ];
    }
}