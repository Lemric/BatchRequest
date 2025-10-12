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
 * Executes a single transaction within a batch.
 */
interface TransactionExecutorInterface
{
    /**
     * Executes a single transaction and returns the response.
     *
     * @return array{code: int, body: mixed, headers?: array<string, string>}
     */
    public function execute(TransactionInterface $transaction): array;
}
