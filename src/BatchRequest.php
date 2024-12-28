<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */

namespace Lemric\BatchRequest;

use Assert\Assertion;
use Assert\AssertionFailedException;
use JsonException;
use Symfony\Component\HttpFoundation\{HeaderBag, JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\{HttpKernelInterface};
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;
use Generator;
use function array_map;
use function end;
use function is_array;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class BatchRequest
{
    private ?LimiterInterface $limiter = null;

    public function __construct(
        private readonly HttpKernelInterface $httpKernel,
        private readonly RequestParser $requestParser,
        private readonly TransactionFactory $transactionFactory,
        private readonly ?RateLimiterFactory $rateLimiterFactory = null
    ) {}

    public function handle(Request $request): JsonResponse
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
