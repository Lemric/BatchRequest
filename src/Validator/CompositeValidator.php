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

/**
 * Composite validator that runs multiple validators.
 */
final readonly class CompositeValidator implements ValidatorInterface
{
    /**
     * @param array<ValidatorInterface> $validators
     */
    public function __construct(
        private array $validators,
    ) {
    }

    public function validate(BatchRequestInterface $batchRequest): void
    {
        foreach ($this->validators as $validator) {
            $validator->validate($batchRequest);
        }
    }
}
