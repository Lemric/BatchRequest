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
 * Thrown when execution fails.
 */
class ExecutionException extends BatchRequestException
{
    public static function transactionFailed(int $index, string $reason): self
    {
        return new self(sprintf('Transaction %d failed: %s', $index, $reason));
    }
}
