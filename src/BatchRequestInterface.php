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

/**
 * Represents an immutable batch request containing multiple operations.
 *
 * @psalm-immutable
 */
interface BatchRequestInterface
{
    /**
     * Returns the number of transactions in this batch.
     */
    public function count(): int;

    /**
     * Returns the client identifier for rate limiting.
     */
    public function getClientIdentifier(): string;

    /**
     * Returns additional metadata about the batch request.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Returns all transactions in this batch request.
     *
     * @return array<int, TransactionInterface>
     */
    public function getTransactions(): array;

    /**
     * Checks if the batch is empty.
     */
    public function isEmpty(): bool;

    /**
     * Checks if headers should be included in responses.
     */
    public function shouldIncludeHeaders(): bool;
}
