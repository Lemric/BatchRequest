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
 * Represents a single HTTP transaction within a batch request.
 *
 * @psalm-immutable
 */
interface TransactionInterface
{
    /**
     * Returns the request body content.
     */
    public function getContent(): string;

    /**
     * Returns cookies for this transaction.
     *
     * @return array<string, string>
     */
    public function getCookies(): array;

    /**
     * Returns uploaded files.
     *
     * @return array<string, mixed>
     */
    public function getFiles(): array;

    /**
     * Returns request headers.
     *
     * @return array<string, string|array<string>>
     */
    public function getHeaders(): array;

    /**
     * Returns the HTTP method.
     */
    public function getMethod(): string;

    /**
     * Returns request parameters (query + body).
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array;

    /**
     * Returns server variables.
     *
     * @return array<string, mixed>
     */
    public function getServerVariables(): array;

    /**
     * Returns the relative URL path.
     */
    public function getUri(): string;
}
