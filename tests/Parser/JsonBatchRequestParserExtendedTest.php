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
}
