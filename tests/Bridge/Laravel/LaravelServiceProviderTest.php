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

namespace Lemric\BatchRequest\Tests\Bridge\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Lemric\BatchRequest\Bridge\Laravel\{LaravelBatchRequestFacade, LaravelServiceProvider};
use PHPUnit\Framework\TestCase;

class LaravelServiceProviderTest extends TestCase
{
    private Container $container;

    private LaravelServiceProvider $serviceProvider;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->serviceProvider = new LaravelServiceProvider($this->container);
    }

    public function testBootMethod(): void
    {
        // Boot method should not throw any exceptions
        $this->serviceProvider->boot();
        $this->assertTrue(true); // If we get here, boot() executed successfully
    }

    public function testRegisterLaravelBatchRequestFacade(): void
    {
        $kernel = $this->createMock(Kernel::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->container
            ->expects($this->once())
            ->method('singleton')
            ->with(
                LaravelBatchRequestFacade::class,
                $this->isType('callable'),
            )
            ->willReturnCallback(function ($abstract, $concrete) {
                // Simulate the callback execution
                $concrete($this->container);
            });

        $this->container
            ->method('make')
            ->willReturnMap([
                ['Illuminate\Contracts\Http\Kernel', $kernel],
                ['log', $logger],
            ]);

        $this->serviceProvider->register();
    }

    public function testServiceProviderCanBeInstantiated(): void
    {
        $this->assertInstanceOf(LaravelServiceProvider::class, $this->serviceProvider);
    }

    public function testServiceProviderExtendsServiceProvider(): void
    {
        $this->assertInstanceOf(ServiceProvider::class, $this->serviceProvider);
    }

    public function testSingletonCallbackCreatesFacadeWithCorrectParameters(): void
    {
        $kernel = $this->createMock(Kernel::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->container
            ->method('make')
            ->willReturnMap([
                ['Illuminate\Contracts\Http\Kernel', $kernel],
                ['log', $logger],
            ]);

        $callback = null;
        $this->container
            ->method('singleton')
            ->willReturnCallback(function ($abstract, $concrete) use (&$callback) {
                if (LaravelBatchRequestFacade::class === $abstract) {
                    $callback = $concrete;
                }
            });

        $this->serviceProvider->register();

        $this->assertNotNull($callback);
        $this->assertIsCallable($callback);

        // Test that the callback creates a LaravelBatchRequestFacade instance
        $facade = $callback($this->container);
        $this->assertInstanceOf(LaravelBatchRequestFacade::class, $facade);
    }
}
