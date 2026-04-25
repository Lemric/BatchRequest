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

use Lemric\BatchRequest\BatchRequestInterface;
use Lemric\BatchRequest\Exception\ValidationException;

/**
 * Validates batch requests for size limits and structure.
 */
final readonly class BatchRequestValidator implements ValidatorInterface
{
    private const DEFAULT_MAX_BATCH_SIZE = 50;

    private const DEFAULT_MAX_TRANSACTION_CONTENT_LENGTH = 262144;

    public function __construct(
        private TransactionValidatorInterface $transactionValidator,
        private int $maxBatchSize = self::DEFAULT_MAX_BATCH_SIZE,
        private int $maxTransactionContentLength = self::DEFAULT_MAX_TRANSACTION_CONTENT_LENGTH,
    ) {
    }

    public function validate(BatchRequestInterface $batchRequest): void
    {
        if ($batchRequest->isEmpty()) {
            throw ValidationException::batchSizeExceeded(0, 1);
        }

        $count = $batchRequest->count();
        if ($count > $this->maxBatchSize) {
            throw ValidationException::batchSizeExceeded($count, $this->maxBatchSize);
        }

        // Defense-in-depth SSRF guard: a sub-request that itself targets
        // the batch endpoint would arrive carrying IS_INTERNAL=true in
        // its metadata. Reject recursive batches outright.
        $metadata = $batchRequest->getMetadata();
        if (true === ($metadata['is_recursive_batch'] ?? false)) {
            throw ValidationException::invalidUrl('Recursive batch requests are not allowed');
        }

        $maxLen = $this->maxTransactionContentLength;
        foreach ($batchRequest->getTransactions() as $transaction) {
            $contentLength = strlen($transaction->getContent());
            if ($contentLength > $maxLen) {
                throw new ValidationException(
                    sprintf('Transaction content size %d exceeds limit of %d bytes', $contentLength, $maxLen),
                    ['size' => (string) $contentLength, 'limit' => (string) $maxLen],
                );
            }

            $this->transactionValidator->validate($transaction);
        }
    }
}
