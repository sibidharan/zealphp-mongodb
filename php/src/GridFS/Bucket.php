<?php
namespace ZealPHP\MongoDB\GridFS;

class Bucket
{
    public function __construct(private int $poolId, private string $dbName, private array $options = []) {}

    public function openUploadStream(string $filename, array $options = []): mixed
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }
    public function uploadFromStream(string $filename, $source, array $options = []): mixed
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }
    public function openDownloadStream(mixed $id): mixed
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }
    public function downloadToStream(mixed $id, $destination): void
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }
    public function find(array|object $filter = [], array $options = []): \Iterator
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }
    public function delete(mixed $id): void
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }
    public function drop(): void
    { throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented'); }

    public function getBucketName(): string { return $this->options['bucketName'] ?? 'fs'; }
    public function getDatabaseName(): string { return $this->dbName; }
}
