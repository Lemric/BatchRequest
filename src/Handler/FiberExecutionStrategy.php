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
 * Fiber-based execution strategy.
 *
 * Groups consecutive read-only transactions (GET/HEAD) for parallel
 * execution via PHP Fibers, while serializing write operations
 * (POST/PUT/PATCH/DELETE) to preserve causal ordering and avoid
 * shared-state corruption in framework containers (EM/PDO/session).
 *
 * Each output group is either:
 *  - a single write transaction (executed sequentially), or
 *  - a contiguous block of read-only transactions (executed in
 *    parallel with bounded concurrency by the handler).
 *
 * Original indexes are preserved by the handler; this strategy only
 * decides batching boundaries.
 *
 * @internal
 */
final readonly class FiberExecutionStrategy implements ExecutionStrategyInterface
{
    private const READ_ONLY_METHODS = ['GET' => true, 'HEAD' => true];

    public function canExecuteInParallel(array $transactions): bool
    {
        foreach ($transactions as $transaction) {
            if (!isset(self::READ_ONLY_METHODS[strtoupper($transaction->getMethod())])) {
                return false;
            }
        }

        return [] !== $transactions;
    }

    public function groupTransactions(array $transactions): array
    {
        $groups = [];
        $current = [];

        foreach ($transactions as $index => $transaction) {
            if (isset(self::READ_ONLY_METHODS[strtoupper($transaction->getMethod())])) {
                $current[$index] = $transaction;

                continue;
            }

            if ([] !== $current) {
                $groups[] = $current;
                $current = [];
            }

            $groups[] = [$index => $transaction];
        }

        if ([] !== $current) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Returns true if a single transaction is read-only and may run in a fiber.
     */
    public function isReadOnly(TransactionInterface $transaction): bool
    {
        return isset(self::READ_ONLY_METHODS[strtoupper($transaction->getMethod())]);
    }
}

