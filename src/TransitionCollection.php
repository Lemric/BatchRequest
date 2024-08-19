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
    public function __construct(private array $elements, readonly Request $mainRequest)
    {
        $this->elements = array_map(callback: fn($subRequest): Transaction => new Transaction($subRequest, $mainRequest), array: $elements);
    }

    public function map(callable $fn): array
    {
        return array_map($fn, $this->elements);
    }

    public function size(): int
    {
        return count($this->elements);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }
}