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

use ArrayIterator;
use IteratorAggregate;
use Symfony\Component\HttpFoundation\Request;
use Traversable;

class TransitionCollection implements IteratorAggregate
{
    /** @var Transaction[] */
    private array $transactions;

    public function __construct(
        private readonly array                      $elements,
        private readonly Request                    $mainRequest,
        private readonly TransactionParameterParser $parameterParser
    ) {
        $this->initializeTransactions();
    }

    private function initializeTransactions(): void
    {
        $this->transactions = array_map(
            fn($subRequest): Transaction => new Transaction($subRequest, $this->mainRequest, $this->parameterParser),
            $this->elements
        );
    }

    public function map(callable $fn): array
    {
        return array_map($fn, $this->transactions);
    }

    public function size(): int
    {
        return count($this->transactions);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->transactions);
    }

    public function getTransactions(): array
    {
        return $this->transactions;
    }
}