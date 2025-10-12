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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\HttpKernelInterface;

use const E_USER_DEPRECATED;

final class BatchRequestBackwardCompatibilityTest extends TestCase
{
    public function testBackwardCompatibilityWrapper(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);

        set_error_handler(static function (int $errno, string $errstr): bool {
            return true;
        });

        $batchRequest = new BatchRequest($httpKernel);

        restore_error_handler();

        $this->assertInstanceOf(BatchRequest::class, $batchRequest);
    }

    public function testDeprecationNoticeTriggered(): void
    {
        $errorTriggered = false;
        $errorMessage = '';

        set_error_handler(static function (int $errno, string $errstr) use (&$errorTriggered, &$errorMessage): bool {
            if (E_USER_DEPRECATED === $errno) {
                $errorTriggered = true;
                $errorMessage = $errstr;
            }

            return true;
        });

        $httpKernel = $this->createMock(HttpKernelInterface::class);
        new BatchRequest($httpKernel);

        restore_error_handler();

        $this->assertTrue($errorTriggered, 'Deprecation notice was not triggered');
        $this->assertStringContainsString('deprecated since version 2.0', $errorMessage);
        $this->assertStringContainsString('will be removed in 3.1', $errorMessage);
    }

    public function testHandleMethod(): void
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel
            ->method('handle')
            ->willReturn(new Response('[]', 200, ['Content-Type' => 'application/json']));

        set_error_handler(static function (int $errno, string $errstr): bool {
            return true;
        });

        $batchRequest = new BatchRequest($httpKernel);

        restore_error_handler();

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            json_encode([['method' => 'GET', 'relative_url' => '/']]),
        );

        $response = $batchRequest->handle($request);

        $this->assertInstanceOf(Response::class, $response);
    }
}
