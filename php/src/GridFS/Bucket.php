<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\GridFS;

use Iterator;
use ZealPHP\MongoDB\Exception\RuntimeException;

class Bucket
{
    public function __construct(private readonly int $poolId, private readonly string $dbName, private array $options = [])
    {
    }

    public function openUploadStream(string $filename, array $options = []): mixed
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function uploadFromStream(string $filename, $source, array $options = []): mixed
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function openDownloadStream(mixed $id): mixed
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function downloadToStream(mixed $id, $destination): void
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function find(array|object $filter = [], array $options = []): Iterator
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function delete(mixed $id): void
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function drop(): void
    {
        throw new RuntimeException('GridFS not yet implemented');
    }

    public function getBucketName(): string
    {
        return $this->options['bucketName'] ?? 'fs';
    }

    public function getDatabaseName(): string
    {
        return $this->dbName;
    }
}
