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

namespace Lemric\BatchRequest\Tests\Validator;

use Lemric\BatchRequest\{BatchRequestInterface, Transaction};
use Lemric\BatchRequest\Exception\ValidationException;
use Lemric\BatchRequest\Model\BatchRequest;
use Lemric\BatchRequest\Validator\{CompositeValidator, ValidatorInterface};
use PHPUnit\Framework\TestCase;

final class CompositeValidatorTest extends TestCase
{
    public function testValidateRunsAllValidators(): void
    {
        $validator1 = $this->createMock(ValidatorInterface::class);
        $validator2 = $this->createMock(ValidatorInterface::class);

        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);

        $validator1->expects($this->once())
            ->method('validate')
            ->with($batchRequest);

        $validator2->expects($this->once())
            ->method('validate')
            ->with($batchRequest);

        $composite = new CompositeValidator([$validator1, $validator2]);
        $composite->validate($batchRequest);
    }

    public function testValidateThrowsFirstException(): void
    {
        $validator1 = new class implements ValidatorInterface {
            public function validate(BatchRequestInterface $batchRequest): void
            {
                throw ValidationException::invalidMethod('TEST');
            }
        };

        $validator2 = $this->createMock(ValidatorInterface::class);
        $validator2->expects($this->never())->method('validate');

        $composite = new CompositeValidator([$validator1, $validator2]);
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid HTTP method: TEST');

        $composite->validate($batchRequest);
    }

    public function testValidateWithEmptyValidatorsList(): void
    {
        $composite = new CompositeValidator([]);
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);

        $composite->validate($batchRequest);
        $this->assertTrue(true);
    }

    public function testValidateWithMultipleValidators(): void
    {
        $callOrder = [];

        $validator1 = new class($callOrder) implements ValidatorInterface {
            public function __construct(private array &$callOrder)
            {
            }

            public function validate(BatchRequestInterface $batchRequest): void
            {
                $this->callOrder[] = 1;
            }
        };

        $validator2 = new class($callOrder) implements ValidatorInterface {
            public function __construct(private array &$callOrder)
            {
            }

            public function validate(BatchRequestInterface $batchRequest): void
            {
                $this->callOrder[] = 2;
            }
        };

        $validator3 = new class($callOrder) implements ValidatorInterface {
            public function __construct(private array &$callOrder)
            {
            }

            public function validate(BatchRequestInterface $batchRequest): void
            {
                $this->callOrder[] = 3;
            }
        };

        $composite = new CompositeValidator([$validator1, $validator2, $validator3]);
        $batchRequest = new BatchRequest([new Transaction('GET', '/api/posts')]);

        $composite->validate($batchRequest);

        $this->assertSame([1, 2, 3], $callOrder);
    }
}
