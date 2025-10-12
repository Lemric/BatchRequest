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

namespace Lemric\BatchRequest\Model;

use Countable;
use Lemric\BatchRequest\BatchRequestInterface;
use Lemric\BatchRequest\TransactionInterface;

use function count;

/**
 * Immutable value object representing a batch request.
 *
 * @psalm-immutable
 */
final readonly class BatchRequest implements BatchRequestInterface, Countable
{
    /**
     * @param array<int, TransactionInterface> $transactions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private array $transactions,
        private bool $includeHeaders = false,
        private string $clientIdentifier = '',
        private array $metadata = [],
    ) {
    }

    public function getTransactions(): array
    {
        return $this->transactions;
    }

    public function count(): int
    {
        return count($this->transactions);
    }

    public function shouldIncludeHeaders(): bool
    {
        return $this->includeHeaders;
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Creates a new BatchRequest with modified includeHeaders flag.
     */
    public function withIncludeHeaders(bool $includeHeaders): self
    {
        return new self(
            $this->transactions,
            $includeHeaders,
            $this->clientIdentifier,
            $this->metadata
        );
    }

    /**
     * Creates a new BatchRequest with a specific transaction replaced.
     */
    public function withTransaction(int $index, TransactionInterface $transaction): self
    {
        $transactions = $this->transactions;
        $transactions[$index] = $transaction;

        return new self(
            $transactions,
            $this->includeHeaders,
            $this->clientIdentifier,
            $this->metadata
        );
    }

    /**
     * Creates a new BatchRequest with additional metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->transactions,
            $this->includeHeaders,
            $this->clientIdentifier,
            array_merge($this->metadata, $metadata)
        );
    }

    /**
     * Checks if the batch is empty.
     */
    public function isEmpty(): bool
    {
        return 0 === $this->count();
    }

    /**
     * Maps each transaction through a callback.
     *
     * @template T
     *
     * @param callable(TransactionInterface, int): T $callback
     *
     * @return array<int, T>
     */
    public function map(callable $callback): array
    {
        $result = [];
        foreach ($this->transactions as $index => $transaction) {
            $result[$index] = $callback($transaction, $index);
        }

        return $result;
    }
}