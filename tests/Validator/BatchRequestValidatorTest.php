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
use PHPUnit\Framework\TestCase;

final class BatchRequestValidatorTest extends TestCase
{
    public function testValidateAcceptsBatchAtLimit(): void
    {
        $validator = new BatchRequestValidator(new TransactionValidator(), 3);

        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts/1'),
            new Transaction('GET', '/api/posts/2'),
            new Transaction('GET', '/api/posts/3'),
        ]);

        $validator->validate($batchRequest);
        $this->assertTrue(true);
    }

    public function testValidateAcceptsValidBatch(): void
    {
        $validator = new BatchRequestValidator(new TransactionValidator(), 10);

        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ]);

        $validator->validate($batchRequest);
        $this->assertTrue(true);
    }

    public function testValidateChecksEveryTransaction(): void
    {
        $validator = new BatchRequestValidator(new TransactionValidator());

        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
            new Transaction('PUT', '/api/data/1'),
            new Transaction('DELETE', '/api/items/2'),
        ]);

        $validator->validate($batchRequest);
        $this->assertTrue(true);
    }

    public function testValidateRejectsBatchExceedingLimit(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new BatchRequestValidator(new TransactionValidator(), 2);

        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts/1'),
            new Transaction('GET', '/api/posts/2'),
            new Transaction('GET', '/api/posts/3'),
        ]);

        $validator->validate($batchRequest);
    }

    public function testValidateRejectsEmptyBatch(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds limit');

        $validator = new BatchRequestValidator(new TransactionValidator());
        $validator->validate(new BatchRequest([]));
    }

    public function testValidateRejectsExceedingDefaultLimit(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new BatchRequestValidator(new TransactionValidator());

        $transactions = [];
        for ($i = 0; $i < 51; ++$i) {
            $transactions[] = new Transaction('GET', "/api/posts/{$i}");
        }

        $batchRequest = new BatchRequest($transactions);
        $validator->validate($batchRequest);
    }

    public function testValidateRejectsInvalidTransaction(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new BatchRequestValidator(new TransactionValidator());

        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('INVALID', '/api/users'),
        ]);

        $validator->validate($batchRequest);
    }

    public function testValidateStopsAtFirstInvalidTransaction(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        $validator = new BatchRequestValidator(new TransactionValidator());

        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('INVALID', '/api/users'),
            new Transaction('ANOTHER_INVALID', '/api/data'),
        ]);

        $validator->validate($batchRequest);
    }

    public function testValidateWithCustomLimit(): void
    {
        $validator = new BatchRequestValidator(new TransactionValidator(), 100);

        $transactions = [];
        for ($i = 0; $i < 100; ++$i) {
            $transactions[] = new Transaction('GET', "/api/posts/{$i}");
        }

        $batchRequest = new BatchRequest($transactions);
        $validator->validate($batchRequest);

        $this->assertTrue(true);
    }

    public function testValidateWithDefaultMaxBatchSize(): void
    {
        $validator = new BatchRequestValidator(new TransactionValidator());

        $transactions = [];
        for ($i = 0; $i < 50; ++$i) {
            $transactions[] = new Transaction('GET', "/api/posts/{$i}");
        }

        $batchRequest = new BatchRequest($transactions);

        $validator->validate($batchRequest);
        $this->assertTrue(true);
    }
}
