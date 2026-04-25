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
 * Immutable value object representing a batch response.
 *
 * @psalm-immutable
 */
final readonly class BatchResponse implements BatchResponseInterface
{
    /**
     * @param array<int, array{code: int, body: mixed, headers?: array<string, string>}> $responses
     */
    public function __construct(
        private array $responses,
    ) {
    }

    /**
     * Factory method for creating an empty response.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Factory method for creating a response from an array.
     *
     * @param array<int, array{code: int, body: mixed, headers?: array<string, string>}> $responses
     */
    public static function fromArray(array $responses): self
    {
        return new self($responses);
    }

    public function getFailureCount(): int
    {
        $count = 0;
        foreach ($this->responses as $response) {
            /** @phpstan-ignore-next-line nullCoalesce.offset */
            if (($response['code'] ?? 500) >= 400) {
                ++$count;
            }
        }

        return $count;
    }

    public function getResponse(int $index): ?array
    {
        return $this->responses[$index] ?? null;
    }

    public function getResponses(): array
    {
        return $this->responses;
    }

    public function isSuccessful(): bool
    {
        foreach ($this->responses as $response) {
            /** @phpstan-ignore-next-line nullCoalesce.offset */
            $code = $response['code'] ?? 500;
            if ($code < 200 || $code >= 300) {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return $this->responses;
    }

    /**
     * Creates a new BatchResponse with an additional response.
     *
     * @param array{code: int, body: mixed, headers?: array<string, string>} $response
     */
    public function withResponse(array $response): self
    {
        return new self([...$this->responses, $response]);
    }
}
