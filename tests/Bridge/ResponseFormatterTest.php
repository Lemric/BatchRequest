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

use Lemric\BatchRequest\Bridge\ResponseFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Verifies that the formatter handles every realistic Content-Type:
 * JSON, problem+json, vendor+json, HTML, plain text, XML/SVG,
 * PDF, PNG, octet-stream, BinaryFileResponse, StreamedResponse and
 * empty/no-content. The crucial property is that the resulting array
 * can always be safely embedded into a JSON batch envelope (no invalid
 * UTF-8 bytes leak into json_encode).
 */
final class ResponseFormatterTest extends TestCase
{
    private ResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ResponseFormatter();
    }

    public function testJsonResponseIsDecodedIntoArray(): void
    {
        $response = new Response('{"id":1,"name":"x"}', 200, ['Content-Type' => 'application/json']);

        $result = $this->formatter->format($response);

        $this->assertSame(['id' => 1, 'name' => 'x'], $result['body']);
        $this->assertArrayNotHasKey('body_encoding', $result);
    }

    public function testProblemJsonIsDecodedIntoArray(): void
    {
        $response = new Response(
            '{"type":"about:blank","title":"Bad","status":400}',
            400,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );

        $result = $this->formatter->format($response);

        $this->assertSame(400, $result['code']);
        $this->assertIsArray($result['body']);
        $this->assertSame('Bad', $result['body']['title']);
    }

    public function testJsonApiVendorSuffixIsDecoded(): void
    {
        $response = new Response('{"data":[]}', 200, ['Content-Type' => 'application/vnd.api+json']);

        $result = $this->formatter->format($response);

        $this->assertSame(['data' => []], $result['body']);
    }

    public function testInvalidJsonKeepsRawStringWithoutBase64(): void
    {
        $response = new Response('not really json', 200, ['Content-Type' => 'application/json']);

        $result = $this->formatter->format($response);

        $this->assertSame('not really json', $result['body']);
        $this->assertArrayNotHasKey('body_encoding', $result);
    }

    public function testHtmlResponseStaysAsString(): void
    {
        $html = '<!doctype html><html><body><h1>Café</h1></body></html>';
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);

        $result = $this->formatter->format($response);

        $this->assertSame($html, $result['body']);
        $this->assertArrayNotHasKey('body_encoding', $result);
    }

    public function testPlainTextResponseStaysAsString(): void
    {
        $response = new Response('hello world', 200, ['Content-Type' => 'text/plain']);

        $result = $this->formatter->format($response);

        $this->assertSame('hello world', $result['body']);
    }

    public function testXmlResponseStaysAsString(): void
    {
        $xml = "<?xml version=\"1.0\"?><root><a>1</a></root>";
        $response = new Response($xml, 200, ['Content-Type' => 'application/xml']);

        $result = $this->formatter->format($response);

        $this->assertSame($xml, $result['body']);
    }

    public function testAtomXmlVendorSuffixStaysAsString(): void
    {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"/>';
        $response = new Response($xml, 200, ['Content-Type' => 'application/atom+xml']);

        $result = $this->formatter->format($response);

        $this->assertSame($xml, $result['body']);
    }

    public function testSvgImageStaysAsString(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>';
        $response = new Response($svg, 200, ['Content-Type' => 'image/svg+xml']);

        $result = $this->formatter->format($response);

        $this->assertSame($svg, $result['body']);
    }

    public function testPngImageIsBase64Encoded(): void
    {
        // 1x1 transparent PNG.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $response = new Response($png, 200, ['Content-Type' => 'image/png']);

        $result = $this->formatter->format($response);

        $this->assertSame('base64', $result['body_encoding']);
        $this->assertSame($png, base64_decode($result['body'], true));
    }

    public function testPdfIsBase64Encoded(): void
    {
        $pdf = "%PDF-1.4\n%\xC2\xA5\xC2\xB1\xC3\xAB\nxref\n0 1\ntrailer<<>>\n%%EOF";
        $response = new Response($pdf, 200, ['Content-Type' => 'application/pdf']);

        $result = $this->formatter->format($response);

        $this->assertSame('base64', $result['body_encoding']);
        $this->assertSame($pdf, base64_decode($result['body'], true));
    }

    public function testOctetStreamIsBase64Encoded(): void
    {
        $bytes = "\x00\x01\x02\xFF\xFEbinary";
        $response = new Response($bytes, 200, ['Content-Type' => 'application/octet-stream']);

        $result = $this->formatter->format($response);

        $this->assertSame('base64', $result['body_encoding']);
        $this->assertSame($bytes, base64_decode($result['body'], true));
    }

    public function testEmptyContentTypeWithBinaryBytesIsBase64Encoded(): void
    {
        $bytes = "\x00\xFFraw";
        $response = new Response($bytes, 200);
        // Symfony's Response constructor sets a default text/html, so we
        // explicitly drop the header to model a missing content type.
        $response->headers->remove('Content-Type');

        $result = $this->formatter->format($response);

        $this->assertSame('base64', $result['body_encoding']);
        $this->assertSame($bytes, base64_decode($result['body'], true));
    }

    public function testNoContentResponseProducesEmptyStringBody(): void
    {
        $response = new Response('', 204);

        $result = $this->formatter->format($response);

        $this->assertSame(204, $result['code']);
        $this->assertSame('', $result['body']);
        $this->assertArrayNotHasKey('body_encoding', $result);
    }

    public function testBinaryFileResponseIsMaterialisedAndBase64Encoded(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'batch-binary-');
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(64);
        file_put_contents($tmp, $bytes);

        try {
            $response = new BinaryFileResponse($tmp, 200, ['Content-Type' => 'image/png']);

            $result = $this->formatter->format($response);

            $this->assertSame('base64', $result['body_encoding']);
            $this->assertSame($bytes, base64_decode($result['body'], true));
        } finally {
            @unlink($tmp);
        }
    }

    public function testStreamedResponseIsMaterialised(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'streamed-payload';
        }, 200, ['Content-Type' => 'text/plain']);

        $result = $this->formatter->format($response);

        $this->assertSame('streamed-payload', $result['body']);
    }

    /**
     * Property critical for the batch envelope: whatever the sub-response
     * looks like, the formatted array must round-trip through json_encode
     * without losing data.
     */
    public function testMixedBatchSerialisesCleanlyAsJson(): void
    {
        $responses = [
            $this->formatter->format(new Response('{"ok":true}', 200, ['Content-Type' => 'application/json'])),
            $this->formatter->format(new Response('<h1>Hi</h1>', 200, ['Content-Type' => 'text/html'])),
            $this->formatter->format(new Response("\x00\x01\xFF", 200, ['Content-Type' => 'application/octet-stream'])),
            $this->formatter->format(new Response('', 204)),
            $this->formatter->format(new Response('{"e":1}', 422, ['Content-Type' => 'application/problem+json'])),
        ];

        $encoded = json_encode($responses, JSON_THROW_ON_ERROR);
        $this->assertNotFalse($encoded);

        $roundTrip = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['ok' => true], $roundTrip[0]['body']);
        $this->assertSame('<h1>Hi</h1>', $roundTrip[1]['body']);
        $this->assertSame('base64', $roundTrip[2]['body_encoding']);
        $this->assertSame("\x00\x01\xFF", base64_decode($roundTrip[2]['body'], true));
        $this->assertSame('', $roundTrip[3]['body']);
        $this->assertSame(['e' => 1], $roundTrip[4]['body']);
    }
}

