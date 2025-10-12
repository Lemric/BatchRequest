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

final class JsonBatchRequestParserTest extends TestCase
{
    private JsonBatchRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new JsonBatchRequestParser();
    }

    public function testParseComplexBatchRequest(): void
    {
        $content = json_encode([
            [
                'method' => 'GET',
                'relative_url' => '/api/posts?page=1',
            ],
            [
                'method' => 'POST',
                'relative_url' => '/api/users',
                'body' => ['name' => 'John', 'email' => 'john@example.com'],
                'headers' => ['Authorization' => 'Bearer token'],
            ],
            [
                'method' => 'DELETE',
                'relative_url' => '/api/posts/123',
            ],
        ]);

        $batchRequest = $this->parser->parse($content);

        $this->assertCount(3, $batchRequest);
        $transactions = $batchRequest->getTransactions();

        $this->assertSame('GET', $transactions[0]->getMethod());
        $this->assertSame(['page' => '1'], $transactions[0]->getParameters());

        $this->assertSame('POST', $transactions[1]->getMethod());
        $this->assertSame(['name' => 'John', 'email' => 'john@example.com'], $transactions[1]->getParameters());

        $this->assertSame('DELETE', $transactions[2]->getMethod());
        $this->assertSame('/api/posts/123', $transactions[2]->getUri());
    }

    public function testParseSetsServerVariables(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = [
            'server' => ['REMOTE_ADDR' => '127.0.0.1'],
        ];

        $batchRequest = $this->parser->parse($content, $context);
        $transaction = $batchRequest->getTransactions()[0];

        $server = $transaction->getServerVariables();
        $this->assertArrayHasKey('REMOTE_ADDR', $server);
        $this->assertArrayHasKey('IS_INTERNAL', $server);
        $this->assertTrue($server['IS_INTERNAL']);
    }

    public function testParseSimpleBatchRequest(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
            ['method' => 'POST', 'relative_url' => '/api/users'],
        ]);

        $batchRequest = $this->parser->parse($content);

        $this->assertCount(2, $batchRequest);
        $transactions = $batchRequest->getTransactions();

        $this->assertSame('GET', $transactions[0]->getMethod());
        $this->assertSame('/api/posts', $transactions[0]->getUri());
        $this->assertSame('POST', $transactions[1]->getMethod());
        $this->assertSame('/api/users', $transactions[1]->getUri());
    }

    public function testParseThrowsExceptionForEmptyContent(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty content');

        $this->parser->parse('');
    }

    public function testParseThrowsExceptionForInvalidJson(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->parser->parse('invalid json');
    }

    public function testParseThrowsExceptionForNonArrayRoot(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Root element must be an array');

        $this->parser->parse(json_encode('string'));
    }

    public function testParseWithAttachedFiles(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'attached_files' => 'file1, file2',
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
        $transaction = $batchRequest->getTransactions()[0];

        $files = $transaction->getFiles();
        $this->assertArrayHasKey('file1', $files);
        $this->assertArrayHasKey('file2', $files);
        $this->assertArrayNotHasKey('file3', $files);
    }

    public function testParseWithBodyAsArray(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => ['title' => 'Test', 'content' => 'Content'],
            ],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame(['title' => 'Test', 'content' => 'Content'], $transaction->getParameters());
    }

    public function testParseWithBodyAsString(): void
    {
        $content = json_encode([
            [
                'method' => 'POST',
                'relative_url' => '/api/posts',
                'body' => 'title=Test&content=Content',
            ],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame(['title' => 'Test', 'content' => 'Content'], $transaction->getParameters());
    }

    public function testParseWithClientIdentifier(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = ['client_identifier' => '127.0.0.1'];
        $batchRequest = $this->parser->parse($content, $context);

        $this->assertSame('127.0.0.1', $batchRequest->getClientIdentifier());
    }

    public function testParseWithHeaders(): void
    {
        $content = json_encode([
            [
                'method' => 'GET',
                'relative_url' => '/api/posts',
                'headers' => ['Authorization' => 'Bearer token'],
            ],
        ]);

        $context = [
            'headers' => ['Content-Type' => 'application/json'],
        ];

        $batchRequest = $this->parser->parse($content, $context);
        $transaction = $batchRequest->getTransactions()[0];

        $headers = $transaction->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertSame('Bearer token', $headers['Authorization']);
    }

    public function testParseWithIncludeHeaders(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts'],
        ]);

        $context = ['include_headers' => true];
        $batchRequest = $this->parser->parse($content, $context);

        $this->assertTrue($batchRequest->shouldIncludeHeaders());
    }

    public function testParseWithQueryParameters(): void
    {
        $content = json_encode([
            ['method' => 'GET', 'relative_url' => '/api/posts?page=1&limit=10'],
        ]);

        $batchRequest = $this->parser->parse($content);
        $transaction = $batchRequest->getTransactions()[0];

        $this->assertSame('/api/posts', $transaction->getUri());
        $this->assertSame(['page' => '1', 'limit' => '10'], $transaction->getParameters());
    }

    public function testSupportsJsonContentType(): void
    {
        $this->assertTrue($this->parser->supports('application/json'));
        $this->assertTrue($this->parser->supports('text/json'));
        $this->assertFalse($this->parser->supports('text/html'));
    }
}
