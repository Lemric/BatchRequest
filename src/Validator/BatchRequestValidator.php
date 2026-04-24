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

namespace Lemric\BatchRequest\Validator;

use Lemric\BatchRequest\{BatchRequestInterface};
use Lemric\BatchRequest\Exception\ValidationException;

/**
 * Validates batch requests for size limits and structure.
 */
final readonly class BatchRequestValidator implements ValidatorInterface
{
    private const DEFAULT_MAX_BATCH_SIZE = 50;

    public function __construct(
        private TransactionValidatorInterface $transactionValidator,
        private int $maxBatchSize = self::DEFAULT_MAX_BATCH_SIZE,
    ) {
    }

    public function validate(BatchRequestInterface $batchRequest): void
    {
        if ($batchRequest->isEmpty()) {
            throw ValidationException::batchSizeExceeded(0, 1);
        }

        if ($batchRequest->count() > $this->maxBatchSize) {
            throw ValidationException::batchSizeExceeded($batchRequest->count(), $this->maxBatchSize);
        }

        foreach ($batchRequest->getTransactions() as $transaction) {
            $this->transactionValidator->validate($transaction);
        }
    }
}
