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

use JsonException;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Exception\HttpException;

use const JSON_THROW_ON_ERROR;

class TransactionFactory
{
    /**
     * @throws HttpException
     * @throws JsonException
     */
    public function create(Request $request): TransitionCollection
    {
        $content = $request->getContent();
        if (json_validate($content)) {
            return new TransitionCollection(
                json_decode($content, true, 512, JSON_THROW_ON_ERROR),
                $request,
                new TransactionParameterParser(),
            );
        }

        throw new HttpException(Response::HTTP_BAD_REQUEST, 'Invalid request');
    }
}
