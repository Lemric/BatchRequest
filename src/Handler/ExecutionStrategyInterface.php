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

namespace Lemric\BatchRequest\Handler;

use Lemric\BatchRequest\TransactionInterface;

/**
 * Strategy for determining execution order (parallel vs sequential).
 */
interface ExecutionStrategyInterface
{
    /**
     * Determines if transactions can be executed in parallel.
     *
     * @param array<int, TransactionInterface> $transactions
     */
    public function canExecuteInParallel(array $transactions): bool;

    /**
     * Groups transactions for execution.
     *
     * @param array<int, TransactionInterface> $transactions
     *
     * @return array<int, array<int, TransactionInterface>>
     */
    public function groupTransactions(array $transactions): array;
}
