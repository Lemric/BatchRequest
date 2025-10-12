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

use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\TransactionInterface;

/**
 * Validates individual transactions.
 */
interface TransactionValidatorInterface
{
    /**
     * Validates a single transaction.
     *
     * @throws ValidationException
     */
    public function validate(TransactionInterface $transaction): void;
}
