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

use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\{HttpKernelInterface};
use Symfony\Component\RateLimiter\{LimiterInterface, RateLimiterFactory};

/**
 * Class BatchRequest.
 */
final class BatchRequest
{
    private readonly RequestParser $requestParser;

    private readonly TransactionFactory $transactionFactory;

    private ?LimiterInterface $limiter = null;

    public function __construct(
        private readonly HttpKernelInterface $httpKernel,
        private readonly ?RateLimiterFactory $rateLimiterFactory = null,
    ) {
        $this->requestParser = new RequestParser();
        $this->transactionFactory = new TransactionFactory();
    }

    public function handle(Request $request): Response
    {
        $this->initializeLimiter($request);
        $includeHeaders = $this->shouldIncludeHeaders($request);

        return $this->requestParser->parse($request, $this->transactionFactory, $this->httpKernel, $includeHeaders, $this->limiter);
    }

    private function initializeLimiter(Request $request): void
    {
        $this->limiter = $this->rateLimiterFactory?->create($request->getClientIp());
    }

    private function shouldIncludeHeaders(Request $request): bool
    {
        if ($request->query->getBoolean('include_headers')) {
            return true;
        }

        return $request->request->getBoolean('include_headers');
    }
}
