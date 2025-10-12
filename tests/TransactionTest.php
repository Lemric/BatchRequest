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

namespace Lemric\BatchRequest\Tests;

use Lemric\BatchRequest\Transaction;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $transaction = new Transaction(
            method: 'POST',
            uri: '/api/users',
            headers: ['Content-Type' => 'application/json'],
            parameters: ['name' => 'John'],
            content: '{"name":"John"}',
            cookies: ['session' => 'abc123'],
            files: ['file' => 'test.txt'],
            serverVariables: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $this->assertSame('POST', $transaction->getMethod());
        $this->assertSame('/api/users', $transaction->getUri());
        $this->assertSame(['Content-Type' => 'application/json'], $transaction->getHeaders());
        $this->assertSame(['name' => 'John'], $transaction->getParameters());
        $this->assertSame('{"name":"John"}', $transaction->getContent());
        $this->assertSame(['session' => 'abc123'], $transaction->getCookies());
        $this->assertSame(['file' => 'test.txt'], $transaction->getFiles());
        $this->assertSame(['REMOTE_ADDR' => '127.0.0.1'], $transaction->getServerVariables());
    }

    public function testFromArrayCreatesTransactionCorrectly(): void
    {
        $data = [
            'method' => 'GET',
            'relative_url' => '/api/posts',
            'headers' => ['Accept' => 'application/json'],
        ];

        $transaction = Transaction::fromArray($data);

        $this->assertSame('GET', $transaction->getMethod());
        $this->assertSame('/api/posts', $transaction->getUri());
        $this->assertSame(['Accept' => 'application/json'], $transaction->getHeaders());
    }

    public function testFromArrayUsesDefaults(): void
    {
        $transaction = Transaction::fromArray([]);

        $this->assertSame('GET', $transaction->getMethod());
        $this->assertSame('/', $transaction->getUri());
        $this->assertSame([], $transaction->getHeaders());
        $this->assertSame([], $transaction->getParameters());
        $this->assertSame('', $transaction->getContent());
    }

    public function testFromArrayWithBodyAsArray(): void
    {
        $data = [
            'method' => 'POST',
            'relative_url' => '/api/posts',
            'body' => ['title' => 'Test', 'content' => 'Content'],
        ];

        $transaction = Transaction::fromArray($data);

        $this->assertSame('{"title":"Test","content":"Content"}', $transaction->getContent());
    }

    public function testFromArrayWithBodyAsString(): void
    {
        $data = [
            'method' => 'POST',
            'relative_url' => '/api/posts',
            'body' => 'raw body content',
        ];

        $transaction = Transaction::fromArray($data);

        $this->assertSame('raw body content', $transaction->getContent());
    }

    public function testImmutability(): void
    {
        $transaction = new Transaction('GET', '/api/posts');

        $modified1 = $transaction->withMethod('POST');
        $modified2 = $transaction->withUri('/api/users');

        $this->assertSame('GET', $transaction->getMethod());
        $this->assertSame('/api/posts', $transaction->getUri());
        $this->assertNotSame($transaction, $modified1);
        $this->assertNotSame($transaction, $modified2);
        $this->assertNotSame($modified1, $modified2);
    }

    public function testWithContentCreatesNewInstance(): void
    {
        $original = new Transaction('POST', '/api/posts', content: 'original');
        $modified = $original->withContent('modified');

        $this->assertSame('original', $original->getContent());
        $this->assertSame('modified', $modified->getContent());
    }

    public function testWithHeadersMergesHeaders(): void
    {
        $original = new Transaction(
            'GET',
            '/api/posts',
            ['Accept' => 'application/json'],
        );

        $modified = $original->withHeaders(['Authorization' => 'Bearer token']);

        $this->assertSame(['Accept' => 'application/json'], $original->getHeaders());
        $this->assertSame(
            ['Accept' => 'application/json', 'Authorization' => 'Bearer token'],
            $modified->getHeaders(),
        );
    }

    public function testWithMethodCreatesNewInstance(): void
    {
        $original = new Transaction('GET', '/api/posts');
        $modified = $original->withMethod('POST');

        $this->assertNotSame($original, $modified);
        $this->assertSame('GET', $original->getMethod());
        $this->assertSame('POST', $modified->getMethod());
        $this->assertSame('/api/posts', $modified->getUri());
    }

    public function testWithUriCreatesNewInstance(): void
    {
        $original = new Transaction('GET', '/api/posts');
        $modified = $original->withUri('/api/users');

        $this->assertNotSame($original, $modified);
        $this->assertSame('/api/posts', $original->getUri());
        $this->assertSame('/api/users', $modified->getUri());
    }
}
