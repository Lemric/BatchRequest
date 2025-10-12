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

use Lemric\BatchRequest\BatchRequest;
use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests for backward compatibility wrapper.
 */
final class BatchRequestBackwardCompatibilityTest extends TestCase
{
    public function testBackwardCompatibilityWrapper(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);

        $batchRequest = new BatchRequest($httpKernel);

        $this->assertInstanceOf(BatchRequest::class, $batchRequest);
    }

    public function testHandleMethod(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new Response('[]', 200, ['Content-Type' => 'application/json']));

        $batchRequest = new BatchRequest($httpKernel);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            json_encode([['method' => 'GET', 'relative_url' => '/']])
        );

        $response = $batchRequest->handle($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testDeprecationNoticeTriggered(): void
    {
        $this->expectDeprecation();
        $this->expectDeprecationMessage('deprecated since version 2.0');

        $httpKernel = $this->createMock(HttpKernelInterface::class);
        new BatchRequest($httpKernel);
    }
}