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
 * Validates batch requests and transactions (OWASP compliance).
 */
interface ValidatorInterface
{
    /**
     * Validates a batch request.
     *
     * @throws ValidationException
     */
    public function validate(BatchRequestInterface $batchRequest): void;
}
