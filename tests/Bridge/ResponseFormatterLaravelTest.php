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

namespace Lemric\BatchRequest\Tests\Bridge;

use Illuminate\Http\JsonResponse as LaravelJsonResponse;
use Illuminate\Http\Response as LaravelResponse;
use Lemric\BatchRequest\Bridge\ResponseFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proves that the framework-agnostic {@see ResponseFormatter} fully
 * supports Laravel response objects too.
 *
 * Laravel does not ship its own `BinaryFileResponse` / `StreamedResponse`
 * — it reuses Symfony's classes directly — and `Illuminate\Http\Response`
 * / `Illuminate\Http\JsonResponse` extend their Symfony counterparts.
 * Therefore the Symfony-typed formatter parameter is satisfied by every
 * realistic Laravel response, and the behaviour is identical.
 */
final class ResponseFormatterLaravelTest extends TestCase
{
    private ResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ResponseFormatter();
    }

    public function testIlluminateResponseExtendsSymfonyResponse(): void
    {
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::class,
            get_parent_class(LaravelResponse::class),
        );
        $this->assertSame(
            \Symfony\Component\HttpFoundation\JsonResponse::class,
            get_parent_class(LaravelJsonResponse::class),
        );
    }

    public function testFormatsLaravelJsonResponse(): void
    {
        $response = new LaravelJsonResponse(['ok' => true, 'id' => 7], 201);

        $result = $this->formatter->format($response);

        $this->assertSame(201, $result['code']);
        $this->assertSame(['ok' => true, 'id' => 7], $result['body']);
        $this->assertArrayNotHasKey('body_encoding', $result);
    }

    public function testFormatsLaravelHtmlResponse(): void
    {
        $html = '<!doctype html><h1>Café</h1>';
        $response = new LaravelResponse($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);

        $result = $this->formatter->format($response);

        $this->assertSame($html, $result['body']);
    }

    public function testFormatsLaravelProblemJsonResponse(): void
    {
        $payload = '{"type":"about:blank","title":"Bad","status":400}';
        $response = new LaravelResponse($payload, 400, ['Content-Type' => 'application/problem+json']);

        $result = $this->formatter->format($response);

        $this->assertIsArray($result['body']);
        $this->assertSame('Bad', $result['body']['title']);
    }

    public function testFormatsLaravelOctetStreamResponseAsBase64(): void
    {
        $bytes = "\x00\x01\x02\xFF\xFEdata";
        $response = new LaravelResponse($bytes, 200, ['Content-Type' => 'application/octet-stream']);

        $result = $this->formatter->format($response);

        $this->assertSame('base64', $result['body_encoding']);
        $this->assertSame($bytes, base64_decode($result['body'], true));
    }

    public function testFormatsLaravelPngResponseAsBase64(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $response = new LaravelResponse($png, 200, ['Content-Type' => 'image/png']);

        $result = $this->formatter->format($response);

        $this->assertSame('base64', $result['body_encoding']);
        $this->assertSame($png, base64_decode($result['body'], true));
    }

    public function testFormatsBinaryFileResponseReturnedFromLaravel(): void
    {
        // Laravel's `response()->download(...)` returns a Symfony
        // BinaryFileResponse (Laravel does not subclass it).
        $tmp = tempnam(sys_get_temp_dir(), 'batch-laravel-');
        $bytes = "%PDF-1.4\n" . random_bytes(64);
        file_put_contents($tmp, $bytes);

        try {
            $response = new BinaryFileResponse($tmp, 200, ['Content-Type' => 'application/pdf']);

            $result = $this->formatter->format($response);

            $this->assertSame('base64', $result['body_encoding']);
            $this->assertSame($bytes, base64_decode($result['body'], true));
        } finally {
            @unlink($tmp);
        }
    }

    public function testFormatsStreamedResponseReturnedFromLaravel(): void
    {
        // Laravel's `response()->stream(...)` returns a Symfony
        // StreamedResponse (Laravel does not subclass it).
        $response = new StreamedResponse(static function (): void {
            echo 'laravel-stream';
        }, 200, ['Content-Type' => 'text/plain']);

        $result = $this->formatter->format($response);

        $this->assertSame('laravel-stream', $result['body']);
    }

    public function testFormatsLaravelNoContentResponse(): void
    {
        $response = new LaravelResponse('', 204);

        $result = $this->formatter->format($response);

        $this->assertSame(204, $result['code']);
        $this->assertSame('', $result['body']);
    }
}

