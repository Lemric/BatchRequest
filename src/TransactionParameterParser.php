<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */

namespace Lemric\BatchRequest;

use JsonException;
use Symfony\Component\HttpFoundation\Request;
use function array_map;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use const JSON_THROW_ON_ERROR;

class TransactionParameterParser
{
    public const JSON_CONTENT_TYPE = 'application/json';

    public const JSON_WWW_FORM_URLENCODED = 'application/x-www-form-urlencoded';

    public function parse(array $subRequest): array
    {
        return array_merge(
            $this->getPayloadParameters($subRequest),
            $this->getQueryParameters($subRequest)
        );
    }

    private function getQueryParameters(array $request): array
    {
        $urlSections = explode('?', (string)($request['relative_url'] ?? ''));
        if (count($urlSections) === 2 && isset($urlSections[1]) && $urlSections[1] !== '') {
            $queryString = $urlSections[1];
            parse_str($queryString, $parameters);
            return $parameters;
        }

        return [];
    }

    private function getPayloadParameters(array $request): array
    {
        $parameters = [];
        if (isset($request['body'], $request['content-type'])) {
            if (self::JSON_CONTENT_TYPE === $request['content-type'] && is_array($request['body'])) {
                return $request['body'];
            }

            if (self::JSON_WWW_FORM_URLENCODED === $request['content-type'] && is_string($request['body'])) {
                parse_str($request['body'], $parameters);
                $parameters = array_map(function ($parameter) {
                    try {
                        return json_decode($parameter, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        return $parameter;
                    }
                }, $parameters);
            }
        }

        return $parameters;
    }
}