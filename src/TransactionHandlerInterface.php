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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

interface TransactionHandlerInterface
{
    public function handleTransaction(Transaction $transaction, HttpKernelInterface $httpKernel): Response;
}
