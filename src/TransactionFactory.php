<?php

namespace Lemric\BatchRequest;

use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
                new TransactionParameterParser()
            );
        }

        throw new HttpException(Response::HTTP_BAD_REQUEST, 'Invalid request');
    }
}