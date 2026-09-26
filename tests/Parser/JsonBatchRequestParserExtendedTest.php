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

namespace Lemric\BatchRequest\Tests\Parser;

use Lemric\BatchRequest\Exception\ParseException;
use Lemric\BatchRequest\Parser\JsonBatchRequestParser;
use PHPUnit\Framework\TestCase;

final class JsonBatchRequestParserExtendedTest extends TestCase
{
    private JsonBatchRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new JsonBatchRequestParser();
    }

    public function testParseAddsInternalServerVariable(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $serverVars = $batchRequest->getTransactions()[0]->getServerVariables();

        $this->assertArrayHasKey('IS_INTERNAL', $serverVars);
        $this->assertTrue($serverVars['IS_INTERNAL']);
    }

    public function testParseBodyOverridesQueryParameters(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts?id=1',
                'body' => ['id' => '2', 'title' => 'Test'],
            ],
        ]);

        $batchRequest = $this->parser->parse($content);
        $params = $batchRequest->getTransactions()[0]->getParameters();

        $this->assertSame('2', $params['id']);
        $this->assertSame('Test', $params['title']);
    }

    public function testParseDoesNotSupportOtherContentTypes(): void
    {
        $this->assertFalse($this->parser->supports('application/xml'));
        $this->assertFalse($this->parser->supports('text/html'));
        $this->assertFalse($this->parser->supports('application/x-www-form-urlencoded'));
    }

    public function testParseMergesServerVariables(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = [
            'server' => ['REMOTE_ADDR' => '192.168.1.1', 'REQUEST_METHOD' => 'POST'],
        ];

        $batchRequest = $this->parser->parse($content, $context);
        $serverVars = $batchRequest->getTransactions()[0]->getServerVariables();

        $this->assertSame('192.168.1.1', $serverVars['REMOTE_ADDR']);
        $this->assertSame('POST', $serverVars['REQUEST_METHOD']);
        $this->assertTrue($serverVars['IS_INTERNAL']);
    }

    public function testParseSupportsJsonContentType(): void
    {
        $this->assertTrue($this->parser->supports('application/json'));
    }

    public function testParseSupportsTextJsonContentType(): void
    {
        $this->assertTrue($this->parser->supports('text/json'));
    }

    public function testParseWithComplexQueryString(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts?filter[name]=test&sort=-created&page=1'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame('/api/posts', $transaction->getUri());
        $this->assertArrayHasKey('page', $transaction->getParameters());
    }

    public function testParseWithContextCookies(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = [
            'cookies' => ['session' => 'abc123', 'user' => 'john'],
        ];

        $batchRequest = $this->parser->parse($content, $context);
        $cookies = $batchRequest->getTransactions()[0]->getCookies();

        $this->assertSame(['session' => 'abc123', 'user' => 'john'], $cookies);
    }

    public function testParseWithEmptyBody(): void
    {
        $content = json_encode([
            ['method' => 'POST', 'relative_url' => '/api/posts', 'body' => ''],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame([], $transaction->getParameters());
    }

    public function testParseWithMixedBodyTypes(): void
    {
        $content = json_encode([
            ['method' => 'POST', 'relative_url' => '/api/posts', 'body' => ['key' => 'value']],
            ['method' => 'POST', 'relative_url' => '/api/users', 'body' => 'string=body'],
            ['method' => 'GET', 'relative_url' => '/api/items'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transactions = $batchRequest->getTransactions();

        $this->assertCount(3, $transactions);
        $this->assertSame(['key' => 'value'], $transactions[0]->getParameters());
        $this->assertSame(['string' => 'body'], $transactions[1]->getParameters());
        $this->assertSame([], $transactions[2]->getParameters());
    }

    public function testParseWithMultipleFiles(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/upload',
                'attached_files' => 'file1,file2,file3',
            ],
        ]);

        $context = [
            'files' => [
                'file1' => 'content1',
                'file2' => 'content2',
                'file3' => 'content3',
                'file4' => 'content4',
            ],
        ];

        $batchRequest = $this->parser->parse($content, $context);
        $files = $batchRequest->getTransactions()[0]->getFiles();

        $this->assertCount(3, $files);
        $this->assertArrayHasKey('file1', $files);
        $this->assertArrayHasKey('file2', $files);
        $this->assertArrayHasKey('file3', $files);
        $this->assertArrayNotHasKey('file4', $files);
    }

    public function testParseWithNestedArrayBody(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => [
                    'title' => 'Test',
                    'meta' => ['tags' => ['php', 'symfony']],
                ],
            ],
        ]);

        $batchRequest = $this->parser->parse($content);
        $params = $batchRequest->getTransactions()[0]->getParameters();

        $this->assertSame('Test', $params['title']);
        $this->assertIsArray($params['meta']);
        $this->assertSame(['php', 'symfony'], $params['meta']['tags']);
    }

    public function testParseWithOnlyQueryParameters(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/search?q=test&type=all'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame('/api/search', $transaction->getUri());
        $this->assertSame(['q' => 'test', 'type' => 'all'], $transaction->getParameters());
    }

    public function testParseWithoutFiles(): void
    {
        $content = json_encode([
            ['method' => 'POST', 'relative_url' => '/api/posts'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $files = $batchRequest->getTransactions()[0]->getFiles();

        $this->assertSame([], $files);
    }

    public function testParseWithoutQueryString(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame('/api/posts', $transaction->getUri());
        $this->assertSame([], $transaction->getParameters());
    }

    public function testParseWithWhitespaceInAttachedFiles(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/upload',
                'attached_files' => ' file1 , file2 , file3 ',
            ],
        ]);

        $context = [
            'files' => [
                'file1' => 'content1',
                'file2' => 'content2',
                'file3' => 'content3',
            ],
        ];

        $batchRequest = $this->parser->parse($content, $context);
        $files = $batchRequest->getTransactions()[0]->getFiles();

        $this->assertCount(3, $files);
    }

    public function testParseSkipsNonArrayRootItems(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/ok'],
            'not-an-object',
            42,
            null,
            ['method' => 'POST', 'relative_url' => '/also-ok'],
        ]);

        $batchRequest = $this->parser->parse($content);

        $this->assertCount(2, $batchRequest);
        $this->assertSame('/ok', $batchRequest->getTransactions()[0]->getUri());
        $this->assertSame('/also-ok', $batchRequest->getTransactions()[1]->getUri());
    }

    public function testParseEmptyArrayYieldsEmptyBatch(): void
    {
        $batchRequest = $this->parser->parse('[]');

        $this->assertTrue($batchRequest->isEmpty());
        $this->assertSame([], $batchRequest->getTransactions());
    }

    public function testParseRejectsOversizedPayload(): void
    {
        $parser = new JsonBatchRequestParser(maxContentLength: 32);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Payload exceeds 32 bytes');

        $parser->parse(str_repeat('a', 33));
    }

    public function testParseRejectsOversizedTransactionBody(): void
    {
        $parser = new JsonBatchRequestParser(maxTransactionContentLength: 16);
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => str_repeat('x', 64),
            ],
        ]);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Transaction body exceeds 16 bytes');

        $parser->parse($content);
    }

    public function testParseDoesNotForwardSensitiveContextHeaders(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = [
            'headers' => [
                'Authorization' => 'Bearer parent-secret',
                'Cookie' => 'session=abc',
                'X-Csrf-Token' => 'csrf',
                'X-Forwarded-For' => '1.2.3.4',
                'Host' => 'evil.example',
                'Accept' => 'application/json',
                'User-Agent' => 'BatchClient/1.0',
            ],
        ];

        $headers = $this->parser->parse($content, $context)->getTransactions()[0]->getHeaders();

        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('BatchClient/1.0', $headers['User-Agent']);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('Cookie', $headers);
        $this->assertArrayNotHasKey('X-Csrf-Token', $headers);
        $this->assertArrayNotHasKey('X-Forwarded-For', $headers);
        $this->assertArrayNotHasKey('Host', $headers);
    }

    public function testParseCustomWhitelistStillBlocksSensitiveHeaders(): void
    {
        $parser = new JsonBatchRequestParser(forwardedHeadersWhitelist: [
            'authorization',
            'x-trace-id',
            'accept',
        ]);

        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = [
            'headers' => [
                'Authorization' => 'Bearer leaked',
                'X-Trace-Id' => 'trace-1',
                'Accept' => '*/*',
            ],
        ];

        $headers = $parser->parse($content, $context)->getTransactions()[0]->getHeaders();

        $this->assertSame('trace-1', $headers['X-Trace-Id']);
        $this->assertSame('*/*', $headers['Accept']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function testParseDropsDisallowedServerVariablesIncludingHttpPrefix(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = [
            'server' => [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_AUTHORIZATION' => 'Bearer from-server',
                'PATH' => '/usr/bin',
                'SCRIPT_FILENAME' => '/var/www/index.php',
            ],
        ];

        $server = $this->parser->parse($content, $context)->getTransactions()[0]->getServerVariables();

        $this->assertSame('10.0.0.1', $server['REMOTE_ADDR']);
        $this->assertTrue($server['IS_INTERNAL']);
        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $server);
        $this->assertArrayNotHasKey('PATH', $server);
        $this->assertArrayNotHasKey('SCRIPT_FILENAME', $server);
    }

    public function testParseAuthorizationFieldOverridesExistingAuthorizationHeader(): void
    {
        $content = json_encode([
            [
                'method' => 'GET',
                'relative_url' => '/api/me',
                'headers' => [
                    'authorization' => 'Bearer stale',
                    'Accept' => 'application/json',
                ],
                'authorization' => 'Bearer fresh',
            ],
        ]);

        $headers = $this->parser->parse($content)->getTransactions()[0]->getHeaders();

        $this->assertSame('Bearer fresh', $headers['Authorization']);
        $this->assertArrayNotHasKey('authorization', $headers);
        $this->assertSame('application/json', $headers['Accept']);
    }

    public function testParseDefaultsMethodAndUriWhenMissing(): void
    {
        $batchRequest = $this->parser->parse(json_encode([[]]));
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame('GET', $transaction->getMethod());
        $this->assertSame('/', $transaction->getUri());
    }

    public function testParseJsonStringBodyIsKeptAsRawContentNotParsedAsForm(): void
    {
        $jsonBody = '{"title":"raw-json-string"}';
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => $jsonBody,
            ],
        ]);

        $transaction = $this->parser->parse($content)->getTransactions()[0];

        $this->assertSame($jsonBody, $transaction->getContent());
        $this->assertSame([], $transaction->getParameters());
    }

    public function testParseAttachedFilesWithOnlyCommasYieldsNoFiles(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/upload',
                'attached_files' => ' , , ',
            ],
        ]);

        $context = ['files' => ['file1' => 'x']];
        $files = $this->parser->parse($content, $context)->getTransactions()[0]->getFiles();

        $this->assertSame([], $files);
    }

    public function testParseRejectsParseStrFieldBomb(): void
    {
        $fields = [];
        for ($i = 0; $i < 1000; ++$i) {
            $fields[] = "k{$i}=v";
        }
        $body = implode('&', $fields);

        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => $body,
            ],
        ]);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Too many parameters');

        $this->parser->parse($content);
    }

    public function testParseAcceptsJustUnderParseStrFieldLimit(): void
    {
        $fields = [];
        for ($i = 0; $i < 999; ++$i) {
            $fields[] = "k{$i}=v";
        }
        $body = implode('&', $fields);

        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => $body,
            ],
        ]);

        $batch = $this->parser->parse($content);

        $this->assertCount(999, $batch->getTransactions()[0]->getParameters());
    }

    public function testParseRejectsNonArrayJsonRoot(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Root element must be an array');
        $this->parser->parse('true');
    }

    public function testParseObjectRootIsTreatedAsAssocMapAndYieldsEmptyTransactions(): void
    {
        // json_decode(..., true) turns {} into an array, so this is not rejected
        // as a non-array root — instead each value is skipped as a non-array item.
        $batch = $this->parser->parse('{"method":"GET","relative_url":"/x"}');

        $this->assertTrue($batch->isEmpty());
    }
}
