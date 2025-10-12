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

namespace Lemric\BatchRequest\Tests;

use Lemric\BatchRequest\BatchResponse;
use PHPUnit\Framework\TestCase;

final class BatchResponseExtendedTest extends TestCase
{
    public function testGetResponsesReturnsCorrectArray(): void
    {
        $responses = [
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 201, 'body' => ['id' => 2]],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame($responses, $batchResponse->getResponses());
        $this->assertCount(2, $batchResponse->getResponses());
    }

    public function testIsSuccessfulWithAllSuccessCodes(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 201, 'body' => []],
            ['code' => 202, 'body' => []],
            ['code' => 204, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertTrue($batchResponse->isSuccessful());
        $this->assertSame(0, $batchResponse->getFailureCount());
    }

    public function testIsSuccessfulWith299StatusCode(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 299, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertTrue($batchResponse->isSuccessful());
    }

    public function testIsSuccessfulReturnsFalseWith300StatusCode(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 300, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertFalse($batchResponse->isSuccessful());
    }

    public function testIsSuccessfulReturnsFalseWith199StatusCode(): void
    {
        $responses = [
            ['code' => 199, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertFalse($batchResponse->isSuccessful());
    }

    public function testGetFailureCountWith4xxErrors(): void
    {
        $responses = [
            ['code' => 400, 'body' => []],
            ['code' => 401, 'body' => []],
            ['code' => 403, 'body' => []],
            ['code' => 404, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(4, $batchResponse->getFailureCount());
    }

    public function testGetFailureCountWith5xxErrors(): void
    {
        $responses = [
            ['code' => 500, 'body' => []],
            ['code' => 501, 'body' => []],
            ['code' => 502, 'body' => []],
            ['code' => 503, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(4, $batchResponse->getFailureCount());
    }

    public function testGetFailureCountWithMixedResults(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 404, 'body' => []],
            ['code' => 201, 'body' => []],
            ['code' => 500, 'body' => []],
            ['code' => 204, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(2, $batchResponse->getFailureCount());
    }

    public function testGetFailureCountHandlesMissingCode(): void
    {
        $responses = [
            ['body' => []],
            ['code' => 200, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(1, $batchResponse->getFailureCount());
    }

    public function testWithResponsePreservesOriginal(): void
    {
        $original = new BatchResponse([
            ['code' => 200, 'body' => []],
        ]);

        $modified = $original->withResponse(['code' => 201, 'body' => []]);

        $this->assertCount(1, $original->getResponses());
        $this->assertCount(2, $modified->getResponses());
        $this->assertNotSame($original, $modified);
    }

    public function testWithResponseAddsToEnd(): void
    {
        $original = new BatchResponse([
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 201, 'body' => ['id' => 2]],
        ]);

        $modified = $original->withResponse(['code' => 202, 'body' => ['id' => 3]]);

        $responses = $modified->getResponses();
        $this->assertSame(['code' => 202, 'body' => ['id' => 3]], $responses[2]);
    }

    public function testEmptyBatchResponseIsSuccessful(): void
    {
        $batchResponse = BatchResponse::empty();

        $this->assertTrue($batchResponse->isSuccessful());
        $this->assertSame(0, $batchResponse->getFailureCount());
        $this->assertSame([], $batchResponse->getResponses());
    }

    public function testFromArrayCreatesCorrectInstance(): void
    {
        $data = [
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 404, 'body' => ['error' => 'Not found']],
        ];

        $batchResponse = BatchResponse::fromArray($data);

        $this->assertSame($data, $batchResponse->toArray());
        $this->assertCount(2, $batchResponse->getResponses());
    }

    public function testToArrayAndGetResponsesReturnSameData(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 201, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame($batchResponse->getResponses(), $batchResponse->toArray());
    }

    public function testGetResponseWithNegativeIndex(): void
    {
        $batchResponse = new BatchResponse([
            ['code' => 200, 'body' => []],
        ]);

        $this->assertNull($batchResponse->getResponse(-1));
    }

    public function testIsSuccessfulWithEmptyResponses(): void
    {
        $batchResponse = new BatchResponse([]);

        $this->assertTrue($batchResponse->isSuccessful());
    }

    public function testResponsesWithVariousHeaders(): void
    {
        $responses = [
            [
                'code' => 200,
                'body' => [],
                'headers' => ['Content-Type' => 'application/json', 'X-Custom' => 'value1'],
            ],
            [
                'code' => 201,
                'body' => [],
                'headers' => ['Content-Type' => 'text/html'],
            ],
        ];

        $batchResponse = new BatchResponse($responses);

        $first = $batchResponse->getResponse(0);
        $second = $batchResponse->getResponse(1);

        $this->assertCount(2, $first['headers']);
        $this->assertCount(1, $second['headers']);
    }

    public function testMultipleWithResponseCalls(): void
    {
        $original = BatchResponse::empty();

        $step1 = $original->withResponse(['code' => 200, 'body' => []]);
        $step2 = $step1->withResponse(['code' => 201, 'body' => []]);
        $step3 = $step2->withResponse(['code' => 202, 'body' => []]);

        $this->assertCount(0, $original->getResponses());
        $this->assertCount(1, $step1->getResponses());
        $this->assertCount(2, $step2->getResponses());
        $this->assertCount(3, $step3->getResponses());
    }
}