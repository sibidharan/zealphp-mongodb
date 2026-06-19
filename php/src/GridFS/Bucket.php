<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\GridFS;

use MongoDB\BSON\ObjectId;
use ZealPHP\MongoDB\ArrayCursor;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;
use ZealPHP\MongoDB\Exception\RuntimeException;

use function fopen;
use function function_exists;
use function fwrite;
use function is_resource;
use function iterator_to_array;
use function rewind;
use function sprintf;
use function stream_get_contents;
use function zealphp_mongodb_gridfs_delete;
use function zealphp_mongodb_gridfs_download;
use function zealphp_mongodb_gridfs_download_by_name;
use function zealphp_mongodb_gridfs_drop;
use function zealphp_mongodb_gridfs_rename;
use function zealphp_mongodb_gridfs_upload;

/**
 * Real GridFS bucket — mongo-php-library `Bucket` API over the Rust
 * driver's GridFsBucket (chunking to `{bucket}.chunks` happens driver-side).
 *
 * Byte model: payloads move whole per call. The open*Stream methods bridge
 * to that model — `openDownloadStream` returns a rewound `php://temp`
 * handle; `openUploadStream` returns a write-through handle whose `fclose()`
 * performs the upload (UploadStreamWrapper).
 */
class Bucket
{
    private const DEFAULT_CHUNK_SIZE = 261120; // 255 KiB — server default

    public function __construct(private readonly int $poolId, private readonly string $dbName, private array $options = [])
    {
        if (! function_exists('zealphp_mongodb_gridfs_upload')) {
            throw new RuntimeException(
                'GridFS requires zealphp_mongodb.so >= 0.3.2 (zealphp_mongodb_gridfs_upload missing).',
            );
        }
    }

    /** '' = driver default bucket ("fs") on the ext call path. */
    private function bucketArg(): string
    {
        return (string) ($this->options['bucketName'] ?? '');
    }

    // ── Upload ──────────────────────────────────────────────────────

    /**
     * Read $source (a readable stream resource) fully and store it.
     * Returns the file id (ObjectId unless `_id` was given in $options).
     */
    public function uploadFromStream(string $filename, $source, array $options = []): mixed
    {
        if (! is_resource($source)) {
            throw new InvalidArgumentException('Expected $source to be a readable stream resource');
        }

        $data = (string) stream_get_contents($source);

        return $this->uploadBytes($filename, $data, $options);
    }

    /**
     * Writable stream whose fclose() performs the upload. The file id is
     * pre-generated so getFileIdForStream() works before close — same
     * contract as the official library.
     *
     * @return resource
     */
    public function openUploadStream(string $filename, array $options = [])
    {
        $id = $options['_id'] ?? new ObjectId();
        $options['_id'] = $id;

        return UploadStreamWrapper::open($this, $filename, $options, $id);
    }

    /** The pre-generated file id for a stream from openUploadStream(). */
    public function getFileIdForStream($stream): mixed
    {
        return UploadStreamWrapper::idForStream($stream);
    }

    /** @internal Shared byte-upload core (also used by the stream wrapper). */
    public function uploadBytes(string $filename, string $data, array $options = []): mixed
    {
        $opts = [];
        if (isset($options['_id'])) {
            $opts['_id'] = Collection::prepareBSON($options['_id']);
        }

        if (isset($options['metadata'])) {
            $opts['metadata'] = Collection::prepareBSON((array) $options['metadata']);
        }

        $chunkSize = $options['chunkSizeBytes'] ?? $this->options['chunkSizeBytes'] ?? null;
        if ($chunkSize !== null) {
            $chunkSize = (int) $chunkSize;
            // Reject chunkSizeBytes < 1 client-side, matching the official driver.
            // Without this the Rust ext loops forever slicing the payload into
            // zero-length chunks, hanging the worker (#66, worker DoS).
            if ($chunkSize < 1) {
                throw new InvalidArgumentException(sprintf(
                    'Expected "chunkSizeBytes" option to be >= 1, %d given',
                    $chunkSize,
                ));
            }

            $opts['chunkSizeBytes'] = $chunkSize;
        }

        $optsOrNull = $opts ?: null;
        $bucket = $this->bucketArg();
        $id = zealphp_mongodb_gridfs_upload($this->poolId, $this->dbName, $bucket, $filename, $data, $optsOrNull);
        $wrapped = Collection::wrapDoc($id);

        // The underlying driver writes metadata:null when none was provided; the
        // official driver OMITS the field entirely. Strip it so the files
        // document matches byte-for-byte (#29).
        if (! isset($options['metadata'])) {
            $this->getFilesCollection()->updateOne(['_id' => $wrapped], ['$unset' => ['metadata' => '']]);
        }

        return $wrapped;
    }

    // ── Download ────────────────────────────────────────────────────

    public function downloadToStream(mixed $id, $destination): void
    {
        if (! is_resource($destination)) {
            throw new InvalidArgumentException('Expected $destination to be a writable stream resource');
        }

        fwrite($destination, $this->downloadBytes($id));
    }

    public function downloadToStreamByName(string $filename, $destination, array $options = []): void
    {
        if (! is_resource($destination)) {
            throw new InvalidArgumentException('Expected $destination to be a writable stream resource');
        }

        $revision = (int) ($options['revision'] ?? -1);
        fwrite($destination, zealphp_mongodb_gridfs_download_by_name($this->poolId, $this->dbName, $this->bucketArg(), $filename, $revision));
    }

    /**
     * Readable stream over the whole file (a rewound php://temp buffer).
     *
     * @return resource
     */
    public function openDownloadStream(mixed $id)
    {
        return self::bytesToStream($this->downloadBytes($id));
    }

    /** @return resource */
    public function openDownloadStreamByName(string $filename, array $options = [])
    {
        $revision = (int) ($options['revision'] ?? -1);

        return self::bytesToStream(
            zealphp_mongodb_gridfs_download_by_name($this->poolId, $this->dbName, $this->bucketArg(), $filename, $revision),
        );
    }

    private function downloadBytes(mixed $id): string
    {
        $idWrapper = ['id' => Collection::prepareBSON($id)];

        return zealphp_mongodb_gridfs_download($this->poolId, $this->dbName, $this->bucketArg(), $idWrapper);
    }

    /** @return resource */
    private static function bytesToStream(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Failed to allocate php://temp stream');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    // ── Management ──────────────────────────────────────────────────

    public function delete(mixed $id): void
    {
        $idWrapper = ['id' => Collection::prepareBSON($id)];
        zealphp_mongodb_gridfs_delete($this->poolId, $this->dbName, $this->bucketArg(), $idWrapper);
    }

    public function deleteByName(string $filename): void
    {
        foreach ($this->find(['filename' => $filename]) as $file) {
            $this->delete($file['_id']);
        }
    }

    public function rename(mixed $id, string $newFilename): void
    {
        $idWrapper = ['id' => Collection::prepareBSON($id)];
        zealphp_mongodb_gridfs_rename($this->poolId, $this->dbName, $this->bucketArg(), $idWrapper, $newFilename);
    }

    public function drop(): void
    {
        zealphp_mongodb_gridfs_drop($this->poolId, $this->dbName, $this->bucketArg());
    }

    // ── Files metadata ──────────────────────────────────────────────

    public function find(array|object $filter = [], array $options = []): ArrayCursor
    {
        $cursor = $this->getFilesCollection()->find($filter, $options);

        return $cursor instanceof ArrayCursor
            ? $cursor
            : new ArrayCursor(iterator_to_array($cursor, false));
    }

    public function findOne(array|object $filter = [], array $options = []): mixed
    {
        return $this->getFilesCollection()->findOne($filter, $options);
    }

    public function getFilesCollection(): Collection
    {
        return new Collection($this->poolId, $this->dbName, $this->getBucketName() . '.files');
    }

    public function getChunksCollection(): Collection
    {
        return new Collection($this->poolId, $this->dbName, $this->getBucketName() . '.chunks');
    }

    public function getBucketName(): string
    {
        return (string) ($this->options['bucketName'] ?? 'fs');
    }

    public function getDatabaseName(): string
    {
        return $this->dbName;
    }

    public function getChunkSizeBytes(): int
    {
        return (int) ($this->options['chunkSizeBytes'] ?? self::DEFAULT_CHUNK_SIZE);
    }
}
