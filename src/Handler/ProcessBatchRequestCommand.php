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

use Lemric\BatchRequest\BatchRequestInterface;

/**
 * Command to process a batch request (CQRS pattern).
 *
 * @psalm-immutable
 */
final readonly class ProcessBatchRequestCommand implements ProcessBatchRequestCommandInterface
{
    public function __construct(
        private BatchRequestInterface $batchRequest,
    ) {
    }

    public function getBatchRequest(): BatchRequestInterface
    {
        return $this->batchRequest;
    }
}
