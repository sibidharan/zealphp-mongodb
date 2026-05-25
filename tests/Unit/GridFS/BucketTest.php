<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\GridFS;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\GridFS\Bucket;

use function fclose;
use function fopen;

class BucketTest extends TestCase
{
    public function testGetBucketNameDefault(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->assertSame('fs', $bucket->getBucketName());
    }

    public function testGetBucketNameCustom(): void
    {
        $bucket = new Bucket(1, 'testdb', ['bucketName' => 'myfiles']);
        $this->assertSame('myfiles', $bucket->getBucketName());
    }

    public function testGetDatabaseName(): void
    {
        $bucket = new Bucket(1, 'mydb');
        $this->assertSame('mydb', $bucket->getDatabaseName());
    }

    public function testOpenUploadStreamThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        $bucket->openUploadStream('test.txt');
    }

    public function testUploadFromStreamThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $stream = fopen('php://memory', 'r');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        try {
            $bucket->uploadFromStream('test.txt', $stream);
        } finally {
            fclose($stream);
        }
    }

    public function testOpenDownloadStreamThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        $bucket->openDownloadStream('some-id');
    }

    public function testDownloadToStreamThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $stream = fopen('php://memory', 'w');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        try {
            $bucket->downloadToStream('some-id', $stream);
        } finally {
            fclose($stream);
        }
    }

    public function testFindThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        $bucket->find();
    }

    public function testDeleteThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        $bucket->delete('some-id');
    }

    public function testDropThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        $bucket->drop();
    }

    public function testFindWithFilterThrowsRuntimeException(): void
    {
        $bucket = new Bucket(1, 'testdb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GridFS not yet implemented');
        $bucket->find(['filename' => 'test.txt']);
    }
}
