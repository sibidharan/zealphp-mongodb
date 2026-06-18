<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Throwable;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;
use ZealPHP\MongoDB\GridFS\Bucket;

use function extension_loaded;
use function fclose;
use function fopen;
use function function_exists;
use function fwrite;
use function getenv;
use function random_bytes;
use function rewind;
use function stream_get_contents;
use function strlen;
use function uniqid;

/**
 * Real GridFS round-trips. Works on any MongoDB (no replica set needed) —
 * uses MONGODB_RS_URI if set, else MONGODB_URI.
 */
class GridFSTest extends TestCase
{
    private static Client $client;
    private static string $db = 'zealphp_gridfs_test';
    private Bucket $bucket;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        if (! function_exists('zealphp_mongodb_gridfs_upload')) {
            self::markTestSkipped('ext too old: no GridFS support');
        }

        $uri = getenv('MONGODB_RS_URI') ?: getenv('MONGODB_URI');
        if ($uri === false || $uri === '') {
            self::markTestSkipped('No MongoDB URI configured');
        }

        try {
            self::$client = new Client($uri);
            self::$client->selectDatabase('admin')->command(['ping' => 1]);
        } catch (Throwable $e) {
            self::markTestSkipped('MongoDB unreachable: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->bucket = self::$client->selectDatabase(self::$db)->selectGridFSBucket(
            ['bucketName' => uniqid('bkt_')],
        );
    }

    protected function tearDown(): void
    {
        $this->bucket->drop();
    }

    /** @return resource */
    private static function streamOf(string $bytes)
    {
        $s = fopen('php://temp', 'r+b');
        fwrite($s, $bytes);
        rewind($s);

        return $s;
    }

    public function testUploadDownloadRoundTrip(): void
    {
        $payload = "hello gridfs \x00\x01\x02 binary-safe";
        $id = $this->bucket->uploadFromStream('greeting.bin', self::streamOf($payload));
        $this->assertInstanceOf(ObjectId::class, $id);

        $out = fopen('php://temp', 'r+b');
        $this->bucket->downloadToStream($id, $out);
        rewind($out);
        $this->assertSame($payload, stream_get_contents($out), 'binary payload must round-trip exactly');
        fclose($out);
    }

    public function testMultiChunkFile(): void
    {
        // > 2 chunks at the 255 KiB default
        $payload = random_bytes(600 * 1024);
        $id = $this->bucket->uploadFromStream('big.bin', self::streamOf($payload));

        $stream = $this->bucket->openDownloadStream($id);
        $back = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame(strlen($payload), strlen($back));
        $this->assertSame($payload, $back, 'multi-chunk payload must round-trip exactly');

        // Chunking really happened server-side
        $chunks = $this->bucket->getChunksCollection()->countDocuments(['files_id' => $id]);
        $this->assertGreaterThan(1, $chunks, 'a 600 KiB file must span multiple chunks');
    }

    public function testOpenUploadStreamUploadsOnClose(): void
    {
        $stream = $this->bucket->openUploadStream('streamed.txt');
        $preId = $this->bucket->getFileIdForStream($stream);
        $this->assertInstanceOf(ObjectId::class, $preId, 'file id must be known BEFORE close');

        fwrite($stream, 'part one, ');
        fwrite($stream, 'part two');
        fclose($stream);

        $back = stream_get_contents($this->bucket->openDownloadStream($preId));
        $this->assertSame('part one, part two', $back);
    }

    public function testDownloadByNameAndRevisions(): void
    {
        $this->bucket->uploadFromStream('versioned.txt', self::streamOf('v1'));
        $this->bucket->uploadFromStream('versioned.txt', self::streamOf('v2'));

        $latest = stream_get_contents($this->bucket->openDownloadStreamByName('versioned.txt'));
        $this->assertSame('v2', $latest, 'default revision -1 = latest');

        $first = stream_get_contents($this->bucket->openDownloadStreamByName('versioned.txt', ['revision' => 0]));
        $this->assertSame('v1', $first, 'revision 0 = original');
    }

    public function testFindDeleteAndMetadata(): void
    {
        $id = $this->bucket->uploadFromStream(
            'meta.txt',
            self::streamOf('m'),
            ['metadata' => ['tag' => 'kept']],
        );

        $doc = $this->bucket->findOne(['filename' => 'meta.txt']);
        $this->assertNotNull($doc);
        $this->assertSame('kept', $doc['metadata']['tag'] ?? null);
        $this->assertSame(1, $doc['length'] ?? null);

        $this->bucket->delete($id);
        $this->assertNull($this->bucket->findOne(['filename' => 'meta.txt']));
        $this->assertSame(0, $this->bucket->getChunksCollection()->countDocuments(['files_id' => $id]), 'chunks must be deleted too');
    }

    public function testRename(): void
    {
        $id = $this->bucket->uploadFromStream('old-name.txt', self::streamOf('x'));
        $this->bucket->rename($id, 'new-name.txt');
        $this->assertNull($this->bucket->findOne(['filename' => 'old-name.txt']));
        $this->assertNotNull($this->bucket->findOne(['filename' => 'new-name.txt']));
    }

    /**
     * #66: chunkSizeBytes < 1 must be rejected client-side with
     * InvalidArgumentException. Previously the ext looped forever slicing the
     * payload into zero-length chunks, hanging the worker (DoS).
     */
    public function testUploadRejectsZeroChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "chunkSizeBytes" option to be >= 1, 0 given');
        $this->bucket->uploadFromStream('dos.bin', self::streamOf('payload'), ['chunkSizeBytes' => 0]);
    }

    public function testUploadRejectsNegativeChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "chunkSizeBytes" option to be >= 1, -5 given');
        $this->bucket->uploadFromStream('dos.bin', self::streamOf('payload'), ['chunkSizeBytes' => -5]);
    }
}
