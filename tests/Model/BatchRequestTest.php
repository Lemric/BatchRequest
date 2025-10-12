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

namespace Lemric\BatchRequest\Tests\Model;

use Lemric\BatchRequest\Model\BatchRequest;
use Lemric\BatchRequest\Transaction;
use PHPUnit\Framework\TestCase;

final class BatchRequestTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ];

        $batchRequest = new BatchRequest(
            transactions: $transactions,
            includeHeaders: true,
            clientIdentifier: '127.0.0.1',
            metadata: ['custom' => 'value']
        );

        $this->assertSame($transactions, $batchRequest->getTransactions());
        $this->assertTrue($batchRequest->shouldIncludeHeaders());
        $this->assertSame('127.0.0.1', $batchRequest->getClientIdentifier());
        $this->assertSame(['custom' => 'value'], $batchRequest->getMetadata());
    }

    public function testCountReturnsNumberOfTransactions(): void
    {
        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('GET', '/api/users'),
        ]);

        $this->assertSame(2, $batchRequest->count());
    }

    public function testIsEmptyReturnsTrueForEmptyBatch(): void
    {
        $batchRequest = new BatchRequest([]);

        $this->assertTrue($batchRequest->isEmpty());
        $this->assertSame(0, $batchRequest->count());
    }

    public function testIsEmptyReturnsFalseForNonEmptyBatch(): void
    {
        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
        ]);

        $this->assertFalse($batchRequest->isEmpty());
    }

    public function testWithIncludeHeadersCreatesNewInstance(): void
    {
        $original = new BatchRequest([], false);
        $modified = $original->withIncludeHeaders(true);

        $this->assertNotSame($original, $modified);
        $this->assertFalse($original->shouldIncludeHeaders());
        $this->assertTrue($modified->shouldIncludeHeaders());
    }

    public function testWithTransactionReplacesTransactionAtIndex(): void
    {
        $transaction1 = new Transaction('GET', '/api/posts');
        $transaction2 = new Transaction('POST', '/api/users');
        $transaction3 = new Transaction('DELETE', '/api/posts/1');

        $original = new BatchRequest([$transaction1, $transaction2]);
        $modified = $original->withTransaction(1, $transaction3);

        $this->assertNotSame($original, $modified);
        $this->assertSame($transaction1, $original->getTransactions()[0]);
        $this->assertSame($transaction2, $original->getTransactions()[1]);
        $this->assertSame($transaction1, $modified->getTransactions()[0]);
        $this->assertSame($transaction3, $modified->getTransactions()[1]);
    }

    public function testWithMetadataMergesMetadata(): void
    {
        $original = new BatchRequest(
            [],
            false,
            '',
            ['key1' => 'value1']
        );

        $modified = $original->withMetadata(['key2' => 'value2']);

        $this->assertSame(['key1' => 'value1'], $original->getMetadata());
        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $modified->getMetadata());
    }

    public function testMapAppliesCallbackToEachTransaction(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ];

        $batchRequest = new BatchRequest($transactions);

        $uris = $batchRequest->map(fn ($t) => $t->getUri());

        $this->assertSame(['/api/posts', '/api/users'], $uris);
    }

    public function testMapIncludesIndexInCallback(): void
    {
        $transactions = [
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ];

        $batchRequest = new BatchRequest($transactions);

        $result = $batchRequest->map(fn ($t, $i) => sprintf('%d: %s', $i, $t->getMethod()));

        $this->assertSame(['0: GET', '1: POST'], $result);
    }

    public function testImmutability(): void
    {
        $transaction = new Transaction('GET', '/api/posts');
        $batchRequest = new BatchRequest([$transaction]);

        $modified1 = $batchRequest->withIncludeHeaders(true);
        $modified2 = $batchRequest->withMetadata(['test' => 'value']);

        $this->assertNotSame($batchRequest, $modified1);
        $this->assertNotSame($batchRequest, $modified2);
        $this->assertNotSame($modified1, $modified2);
        $this->assertFalse($batchRequest->shouldIncludeHeaders());
        $this->assertSame([], $batchRequest->getMetadata());
    }

    public function testCountableInterface(): void
    {
        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ]);

        $this->assertCount(2, $batchRequest);
    }
}