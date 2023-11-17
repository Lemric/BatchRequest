<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */

namespace Lemric\BatchRequest\Tests;

use Lemric\BatchRequest\BatchRequest;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;

class BatchRequestTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testHandleGet(): void
    {
        $httpKernel = $this->createMock(HttpKernel::class);
        $request = new Request([], [
            'include_headers' => 'false',
        ], [], [], [], [], '[{"method":"GET","relative_url":"/"},{"method":"GET","relative_url":"/"}]');
        $batchRequest = new BatchRequest($httpKernel);
        $this->assertSame('[{"code":200,"body":[]},{"code":200,"body":[]}]', $batchRequest->handle($request)->getContent());
    }

    /**
     * @throws Exception
     */
    public function testHandleMixed(): void
    {
        $httpKernel = $this->createMock(HttpKernel::class);
        $request = new Request([], [
            'include_headers' => 'false',
        ], [], [], [], [], '[{"method":"POST","relative_url":"/"},{"method":"GET","relative_url":"/"}]');
        $batchRequest = new BatchRequest($httpKernel);
        $this->assertSame('[{"code":200,"body":[]},{"code":200,"body":[]}]', $batchRequest->handle($request)->getContent());
    }

    /**
     * @throws Exception
     */
    public function testHandlePost(): void
    {
        $httpKernel = $this->createMock(HttpKernel::class);
        $request = new Request([], [
            'include_headers' => 'false',
        ], [], [], [], [], '[{"method":"POST","relative_url":"/"},{"method":"POST","relative_url":"/"}]');
        $batchRequest = new BatchRequest($httpKernel);
        $this->assertSame('[{"code":200,"body":[]},{"code":200,"body":[]}]', $batchRequest->handle($request)->getContent());
    }

    /**
     * @throws Exception
     */
    public function testHandleUpload(): void
    {
        $httpKernel = $this->createMock(HttpKernel::class);
        $file1 = $this->createMock(UploadedFile::class);
        $file2 = $this->createMock(UploadedFile::class);
        $file3 = $this->createMock(UploadedFile::class);
        $request = new Request([], [
            'include_headers' => 'false',
        ], [], [], [
            'file1' => $file1,
            'file2' => $file2,
            'file3' => $file3,
        ], [], '[{"method":"POST","relative_url":"me/photos","body":"message=My cat photo","attached_files":"file1 ,file2"},{"method":"POST","relative_url":"me/photos","body":"message=My dog photo","attached_files":"file3"}]');
        $batchRequest = new BatchRequest($httpKernel);
        $this->assertSame('[{"code":200,"body":[]},{"code":200,"body":[]}]', $batchRequest->handle($request)->getContent());
    }

    /**
     * @throws Exception
     */
    public function testHandleWithHeaders(): void
    {
        $httpKernel = $this->createMock(HttpKernel::class);
        $request = new Request([], [
            'include_headers' => 'true',
        ], [], [], [], [], '[{"method":"POST","relative_url":"/"},{"method":"GET","relative_url":"/"}]');
        $batchRequest = new BatchRequest($httpKernel);
        $this->assertSame('[{"code":200,"body":[],"headers":{"content-type":"application\/json"}},{"code":200,"body":[],"headers":{"content-type":"application\/json"}}]', $batchRequest->handle($request)->getContent());
    }
}
