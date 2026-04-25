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
use function fclose;
use function feof;
use function fopen;
use function fread;
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
 * @internal
 */
final class ResponseFormatter
{
    /**
     * Read chunk size for streaming binary responses. Multiple of 3 so
     * that base64 encoding can be applied per-chunk without padding
     * artefacts (each 3 bytes encode to 4 base64 chars).
     */
    private const BINARY_CHUNK_SIZE = 8190;

    /**
     * Media types that carry text payloads but are not JSON.
     *
     * O(1) lookup via flipped map.
     *
     * @var array<string, true>
     */
    private const TEXT_MEDIA_TYPES = [
        'application/xml' => true,
        'application/xhtml+xml' => true,
        'application/javascript' => true,
        'application/ecmascript' => true,
        'application/x-javascript' => true,
        'application/x-www-form-urlencoded' => true,
        'application/graphql' => true,
        'application/yaml' => true,
        'application/x-yaml' => true,
        'application/sql' => true,
        'image/svg+xml' => true,
    ];

    /**
     * Headers stripped from sub-responses to prevent leaking internal
     * session / debugging metadata into the consolidated batch envelope.
     *
     * @var array<string, true>
     */
    private const STRIPPED_HEADERS = [
        'set-cookie' => true,
        'authorization' => true,
        'proxy-authenticate' => true,
        'proxy-authorization' => true,
        'server' => true,
        'x-powered-by' => true,
    ];

    /**
     * Pre-compiled regex (single backtrack) for detecting any JSON
     * structured-syntax suffix per RFC 6839 §3.1.
     */
    private const JSON_SUFFIX_REGEX = '~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.-]+\+json$~';

    /**
     * Pre-compiled regex for `application/*+xml` structured-syntax
     * suffix (RFC 7303).
     */
    private const XML_SUFFIX_REGEX = '~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.-]+\+xml$~';

    /**
     * Formats a response into the batch sub-response shape.
     *
     * @return array{code: int, body: mixed, headers: array<string, string>, body_encoding?: string}
     */
    public function format(Response $response): array
    {
        $contentType = (string) ($response->headers->get('Content-Type') ?? '');
        $isBinaryStream = false;
        $rawBody = $this->materialiseBody($response, $isBinaryStream);

        $result = [
            'code' => $response->getStatusCode() ?: Response::HTTP_OK,
            'body' => $rawBody,
            'headers' => $this->extractHeaders($response),
        ];

        if ('' === $rawBody && !$isBinaryStream) {
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
                // Malformed JSON: keep as raw string.
            }

            return $result;
        }

        if ($this->isTextContentType($contentType)) {
            return $result;
        }

        // Binary: payload must be base64 to survive the JSON envelope.
        // Streaming materialiser may already have produced a base64
        // string; only encode otherwise.
        if (!$isBinaryStream) {
            $result['body'] = base64_encode($rawBody);
        }
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
            if (isset(self::STRIPPED_HEADERS[strtolower((string) $name)])) {
                continue;
            }
            $count = count($values);
            $headers[$name] = 0 === $count ? '' : (string) $values[$count - 1];
        }

        return $headers;
    }

    /**
     * RFC 6838/6839: matches `application/json`, `text/json` and any
     * `+json` structured-syntax suffix; ignores parameters such as charset.
     */
    private function isJsonContentType(string $contentType): bool
    {
        $mediaType = $this->mediaType($contentType);

        if ('application/json' === $mediaType || 'text/json' === $mediaType) {
            return true;
        }

        return 1 === preg_match(self::JSON_SUFFIX_REGEX, $mediaType);
    }

    /**
     * Returns true for media types whose payload is UTF-8 text and can
     * be safely embedded in the JSON batch envelope as a string.
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

        if (isset(self::TEXT_MEDIA_TYPES[$mediaType])) {
            return true;
        }

        return 1 === preg_match(self::XML_SUFFIX_REGEX, $mediaType);
    }

    /**
     * Materialises the response body into a string. For
     * `BinaryFileResponse` performs **chunked base64 encoding**
     * directly from disk so peak RAM ≈ chunk size (8 KiB) instead of
     * the full file size. The caller is informed via the by-ref
     * `$isBinaryStream` flag that the returned string is already
     * base64-encoded and must not be encoded again.
     */
    private function materialiseBody(Response $response, bool &$isBinaryStream): string
    {
        $isBinaryStream = false;

        if ($response instanceof BinaryFileResponse) {
            $path = $response->getFile()->getPathname();
            $handle = @fopen($path, 'rb');
            if (false === $handle) {
                return '';
            }

            $encoded = '';
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, self::BINARY_CHUNK_SIZE);
                    if (false === $chunk || '' === $chunk) {
                        break;
                    }
                    $encoded .= base64_encode($chunk);
                }
            } finally {
                fclose($handle);
            }

            $isBinaryStream = true;

            return $encoded;
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

