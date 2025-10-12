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

final class BatchResponseTest extends TestCase
{
    public function testConstructorSetsResponses(): void
    {
        $responses = [
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 201, 'body' => ['id' => 2]],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame($responses, $batchResponse->getResponses());
    }

    public function testGetResponseReturnsSpecificResponse(): void
    {
        $responses = [
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 201, 'body' => ['id' => 2]],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(['code' => 200, 'body' => ['id' => 1]], $batchResponse->getResponse(0));
        $this->assertSame(['code' => 201, 'body' => ['id' => 2]], $batchResponse->getResponse(1));
    }

    public function testGetResponseReturnsNullForInvalidIndex(): void
    {
        $batchResponse = new BatchResponse([
            ['code' => 200, 'body' => []],
        ]);

        $this->assertNull($batchResponse->getResponse(999));
    }

    public function testIsSuccessfulReturnsTrueWhenAllSuccessful(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 201, 'body' => []],
            ['code' => 204, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertTrue($batchResponse->isSuccessful());
    }

    public function testIsSuccessfulReturnsFalseWhenAnyFailed(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 404, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertFalse($batchResponse->isSuccessful());
    }

    public function testIsSuccessfulReturnsFalseForServerErrors(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 500, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertFalse($batchResponse->isSuccessful());
    }

    public function testGetFailureCountReturnsCorrectCount(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 404, 'body' => []],
            ['code' => 500, 'body' => []],
            ['code' => 201, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(2, $batchResponse->getFailureCount());
    }

    public function testGetFailureCountReturnsZeroWhenAllSuccessful(): void
    {
        $responses = [
            ['code' => 200, 'body' => []],
            ['code' => 201, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(0, $batchResponse->getFailureCount());
    }

    public function testToArrayReturnsResponsesArray(): void
    {
        $responses = [
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 404, 'body' => ['error' => 'Not found']],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame($responses, $batchResponse->toArray());
    }

    public function testWithResponseAddsNewResponse(): void
    {
        $original = new BatchResponse([
            ['code' => 200, 'body' => []],
        ]);

        $modified = $original->withResponse(['code' => 201, 'body' => ['id' => 2]]);

        $this->assertNotSame($original, $modified);
        $this->assertCount(1, $original->getResponses());
        $this->assertCount(2, $modified->getResponses());
        $this->assertSame(['code' => 201, 'body' => ['id' => 2]], $modified->getResponse(1));
    }

    public function testEmptyCreatesEmptyResponse(): void
    {
        $batchResponse = BatchResponse::empty();

        $this->assertSame([], $batchResponse->getResponses());
        $this->assertSame(0, $batchResponse->getFailureCount());
        $this->assertTrue($batchResponse->isSuccessful());
    }

    public function testFromArrayCreatesResponseFromArray(): void
    {
        $data = [
            ['code' => 200, 'body' => ['id' => 1]],
            ['code' => 201, 'body' => ['id' => 2]],
        ];

        $batchResponse = BatchResponse::fromArray($data);

        $this->assertSame($data, $batchResponse->getResponses());
    }

    public function testResponsesWithHeaders(): void
    {
        $responses = [
            [
                'code' => 200,
                'body' => [],
                'headers' => ['Content-Type' => 'application/json'],
            ],
        ];

        $batchResponse = new BatchResponse($responses);

        $response = $batchResponse->getResponse(0);
        $this->assertArrayHasKey('headers', $response);
        $this->assertSame(['Content-Type' => 'application/json'], $response['headers']);
    }

    public function testImmutability(): void
    {
        $original = new BatchResponse([
            ['code' => 200, 'body' => []],
        ]);

        $modified = $original->withResponse(['code' => 201, 'body' => []]);

        $this->assertNotSame($original, $modified);
        $this->assertCount(1, $original->getResponses());
        $this->assertCount(2, $modified->getResponses());
    }

    public function testClientErrorsCountedAsFailures(): void
    {
        $responses = [
            ['code' => 400, 'body' => []],
            ['code' => 401, 'body' => []],
            ['code' => 403, 'body' => []],
            ['code' => 404, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(4, $batchResponse->getFailureCount());
        $this->assertFalse($batchResponse->isSuccessful());
    }

    public function testRedirectsNotCountedAsFailures(): void
    {
        $responses = [
            ['code' => 301, 'body' => []],
            ['code' => 302, 'body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertSame(0, $batchResponse->getFailureCount());
        $this->assertFalse($batchResponse->isSuccessful());
    }

    public function testMissingCodeDefaultsTo500(): void
    {
        $responses = [
            ['body' => []],
        ];

        $batchResponse = new BatchResponse($responses);

        $this->assertFalse($batchResponse->isSuccessful());
        $this->assertSame(1, $batchResponse->getFailureCount());
    }
}