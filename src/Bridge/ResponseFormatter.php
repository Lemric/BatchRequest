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

namespace Lemric\BatchRequest\Bridge;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use function base64_encode;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function trim;
use const JSON_THROW_ON_ERROR;

/**
 * Converts an HTTP Response into the batch sub-response shape.
 *
 * Works for **both** Symfony and Laravel: `Illuminate\Http\Response`
 * and `Illuminate\Http\JsonResponse` extend their Symfony counterparts,
 * and Laravel reuses Symfony's `BinaryFileResponse` / `StreamedResponse`
 * directly (it does not ship its own). The Symfony type-hint therefore
 * accepts every realistic Laravel response object as well, which is why
 * this formatter lives in the framework-agnostic `Bridge` namespace
 * rather than under `Bridge\Symfony` or `Bridge\Laravel`.
 *
 * Handles three response classes:
 *  - JSON (`application/json`, `text/json`, `* /* +json` per RFC 6839 §3.1)
 *    is decoded into an `array` `body`.
 *  - Textual responses (`text/*`, `application/xml`, `application/*+xml`,
 *    `application/javascript`, etc.) are passed through as a UTF-8 string
 *    `body`.
 *  - Anything else is treated as binary: the body is base64-encoded and
 *    the marker `body_encoding => 'base64'` is added so the client can
 *    decode it. This is required because the outer batch envelope is
 *    serialised via `JsonResponse`/`json_encode` which rejects non-UTF-8
 *    payloads.
 *
 * `BinaryFileResponse` and `StreamedResponse` (whose `getContent()`
 * returns `false`) are materialised explicitly so file/stream payloads
 * are not silently dropped.
 *
 * @internal
 */
final class ResponseFormatter
{
    /**
     * Media types that carry text payloads but are not JSON.
     */
    private const TEXT_MEDIA_TYPES = [
        'application/xml',
        'application/xhtml+xml',
        'application/javascript',
        'application/ecmascript',
        'application/x-javascript',
        'application/x-www-form-urlencoded',
        'application/graphql',
        'application/yaml',
        'application/x-yaml',
        'application/sql',
        'image/svg+xml',
    ];

    /**
     * Formats a response into the batch sub-response shape.
     *
     * @return array{code: int, body: mixed, headers: array<string, string>, body_encoding?: string}
     */
    public function format(Response $response): array
    {
        $contentType = (string) ($response->headers->get('Content-Type') ?? '');
        $rawBody = $this->materialiseBody($response);

        $result = [
            'code' => $response->getStatusCode() ?: Response::HTTP_OK,
            'body' => $rawBody,
            'headers' => $this->extractHeaders($response),
        ];

        if ('' === $rawBody) {
            return $result;
        }

        if ($this->isJsonContentType($contentType)) {
            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $result['body'] = $decoded;

                    return $result;
                }
            } catch (Throwable) {
                // Malformed JSON: keep as raw string. The server claimed JSON,
                // so the payload is expected to be UTF-8 text — do not
                // base64-encode it.
            }

            return $result;
        }

        if ($this->isTextContentType($contentType)) {
            return $result;
        }

        // Treat everything else as binary so the batch envelope (which is
        // itself JSON-encoded) cannot be corrupted by non-UTF-8 bytes.
        $result['body'] = base64_encode($rawBody);
        $result['body_encoding'] = 'base64';

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function extractHeaders(Response $response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            if (is_array($values)) {
                $headers[$name] = (string) end($values);
            } else {
                $headers[$name] = (string) $values;
            }
        }

        return $headers;
    }

    /**
     * RFC 6838/6839: matches `application/json`, `text/json` and any
     * `+json` structured-syntax suffix; ignores parameters such as
     * charset.
     */
    private function isJsonContentType(string $contentType): bool
    {
        $mediaType = $this->mediaType($contentType);

        if ('application/json' === $mediaType || 'text/json' === $mediaType) {
            return true;
        }

        return 1 === preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.-]+\+json$~', $mediaType);
    }

    /**
     * Returns true for media types whose payload is UTF-8 text and can
     * be safely embedded in the JSON batch envelope as a string.
     *
     * Empty/unknown content types are treated as binary (false) so that
     * misconfigured backends don't corrupt the envelope.
     */
    private function isTextContentType(string $contentType): bool
    {
        $mediaType = $this->mediaType($contentType);

        if ('' === $mediaType) {
            return false;
        }

        if (str_starts_with($mediaType, 'text/')) {
            return true;
        }

        if (in_array($mediaType, self::TEXT_MEDIA_TYPES, true)) {
            return true;
        }

        // application/*+xml structured-syntax suffix (RFC 7303).
        return 1 === preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.-]+\+xml$~', $mediaType);
    }

    /**
     * Materialises the response body into a string, including special
     * cases (`BinaryFileResponse`, `StreamedResponse`) where
     * `getContent()` returns `false`.
     */
    private function materialiseBody(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            $path = $response->getFile()->getPathname();
            $contents = @file_get_contents($path);

            return false === $contents ? '' : $contents;
        }

        if ($response instanceof StreamedResponse) {
            if (ob_start()) {
                try {
                    $response->sendContent();
                } catch (Throwable) {
                    ob_end_clean();

                    return '';
                }
                $captured = ob_get_clean();

                return is_string($captured) ? $captured : '';
            }

            return '';
        }

        $content = $response->getContent();

        return false === $content ? '' : $content;
    }

    private function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}

