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

namespace Lemric\BatchRequest\Tests\Handler;

use Lemric\BatchRequest\Handler\ProcessBatchRequestCommand;
use Lemric\BatchRequest\Model\BatchRequest;
use Lemric\BatchRequest\Transaction;
use PHPUnit\Framework\TestCase;

final class ProcessBatchRequestCommandTest extends TestCase
{
    public function testConstructorSetsBatchRequest(): void
    {
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->assertSame($batchRequest, $command->getBatchRequest());
    }

    public function testGetBatchRequestReturnsSameInstance(): void
    {
        $batchRequest = new BatchRequest([
            new Transaction('GET', '/api/posts'),
            new Transaction('POST', '/api/users'),
        ]);

        $command = new ProcessBatchRequestCommand($batchRequest);

        $result = $command->getBatchRequest();

        $this->assertSame($batchRequest, $result);
        $this->assertCount(2, $result);
    }

    public function testCommandIsImmutable(): void
    {
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $retrievedBatchRequest = $command->getBatchRequest();

        $this->assertSame($batchRequest, $retrievedBatchRequest);
    }

    public function testCommandWithEmptyBatchRequest(): void
    {
        $batchRequest = new BatchRequest([]);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $result = $command->getBatchRequest();

        $this->assertCount(0, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testCommandWithLargeBatchRequest(): void
    {
        $transactions = [];
        for ($i = 0; $i < 100; ++$i) {
            $transactions[] = new Transaction('GET', "/api/posts/{$i}");
        }

        $batchRequest = new BatchRequest($transactions);
        $command = new ProcessBatchRequestCommand($batchRequest);

        $this->assertCount(100, $command->getBatchRequest());
    }

    public function testCommandPreservesMetadata(): void
    {
        $batchRequest = new BatchRequest(
            [new Transaction('GET', '/api/posts')],
            true,
            '127.0.0.1',
            ['custom' => 'metadata']
        );

        $command = new ProcessBatchRequestCommand($batchRequest);
        $result = $command->getBatchRequest();

        $this->assertTrue($result->shouldIncludeHeaders());
        $this->assertSame('127.0.0.1', $result->getClientIdentifier());
        $this->assertSame(['custom' => 'metadata'], $result->getMetadata());
    }
}