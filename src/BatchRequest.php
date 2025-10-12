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

namespace Lemric\BatchRequest;

use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Backward compatibility wrapper for v1.x API.
 *
 * @deprecated Use SymfonyBatchRequestFacade instead. This class will be removed in v3.0.
 */
final class BatchRequest
{
    private readonly SymfonyBatchRequestFacade $facade;

    public function __construct(
        HttpKernelInterface $httpKernel,
        ?RateLimiterFactory $rateLimiterFactory = null,
    ) {
        @trigger_error(
            sprintf(
                'The "%s" class is deprecated since version 2.0 and will be removed in 3.0. ' .
                'Use "%s" instead.',
                self::class,
                SymfonyBatchRequestFacade::class
            ),
            E_USER_DEPRECATED
        );

        $this->facade = new SymfonyBatchRequestFacade(
            $httpKernel,
            $rateLimiterFactory
        );
    }

    /**
     * Handles a batch request.
     *
     * @deprecated Use SymfonyBatchRequestFacade::handle() instead
     */
    public function handle(Request $request): Response
    {
        return $this->facade->handle($request);
    }
}