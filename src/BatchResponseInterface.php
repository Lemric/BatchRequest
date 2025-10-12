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
 * Represents the consolidated response from a batch request.
 *
 * @psalm-immutable
 */
interface BatchResponseInterface
{
    /**
     * Returns the number of failed transactions.
     */
    public function getFailureCount(): int;

    /**
     * Returns response for a specific transaction index.
     *
     * @return array{code: int, body: mixed, headers?: array<string, string>>|null
     */
    public function getResponse(int $index): ?array;

    /**
     * Returns all transaction responses.
     *
     * @return array<int, array{code: int, body: mixed, headers?: array<string, string>}>
     */
    public function getResponses(): array;

    /**
     * Checks if all transactions were successful (2xx status codes).
     */
    public function isSuccessful(): bool;

    /**
     * Converts the response to an array format.
     *
     * @return array<int, array{code: int, body: mixed, headers?: array<string, string>}>
     */
    public function toArray(): array;
}
