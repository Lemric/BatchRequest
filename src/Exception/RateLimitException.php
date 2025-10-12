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

namespace Lemric\BatchRequest\Exception;

/**
 * Thrown when rate limit is exceeded.
 */
class RateLimitException extends BatchRequestException
{
    public function __construct(
        string $message = 'Rate limit exceeded',
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
