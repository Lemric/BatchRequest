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

use Lemric\BatchRequest\BatchResponseInterface;

/**
 * Handler for processing batch requests (Command pattern).
 */
interface BatchRequestHandlerInterface
{
    /**
     * Handles a batch request command.
     */
    public function handle(ProcessBatchRequestCommandInterface $command): BatchResponseInterface;
}
