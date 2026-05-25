<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use ZealPHP\MongoDB\ArrayCursor;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Database;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\GridFS\Bucket;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\WriteConcern;

use function extension_loaded;
use function getenv;
use function uniqid;

class DatabaseTest extends TestCase
{
    private static Client $client;
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        self::$client = new Client(getenv('MONGODB_URI') ?: 'mongodb://db.selfmade.ninja:27017');
    }

    protected function setUp(): void
    {
        $this->db = self::$client->selectDatabase('zealphp_phpunit_' . uniqid());
    }

    protected function tearDown(): void
    {
        try {
            $this->db->drop();
        } catch (Throwable) {
        }
    }

    public function testCommandPing(): void
    {
        $result = $this->db->command(['ping' => 1]);
        $this->assertArrayHasKey('ok', $result);
        $this->assertEquals(1, $result['ok']);
    }

    public function testCreateAndDropCollection(): void
    {
        $this->db->createCollection('test_col');
        $names = $this->db->listCollectionNames();
        $this->assertContains('test_col', $names);

        $this->db->dropCollection('test_col');
        $names = $this->db->listCollectionNames();
        $this->assertNotContains('test_col', $names);
    }

    public function testSelectCollectionMagicGet(): void
    {
        $col = $this->db->myCollection;
        $this->assertInstanceOf(Collection::class, $col);
        $this->assertSame('myCollection', $col->getCollectionName());
    }

    public function testToString(): void
    {
        $this->assertSame($this->db->getDatabaseName(), (string) $this->db);
    }

    public function testSelectGridFSBucket(): void
    {
        $bucket = $this->db->selectGridFSBucket();
        $this->assertInstanceOf(Bucket::class, $bucket);
        $this->assertSame('fs', $bucket->getBucketName());

        $this->expectException(RuntimeException::class);
        $bucket->find();
    }

    public function testWithOptions(): void
    {
        $new = $this->db->withOptions(['readConcern' => 'local']);
        $this->assertInstanceOf(Database::class, $new);
        $this->assertNotSame($this->db, $new);
    }

    public function testListCollections(): void
    {
        $this->db->createCollection('list_test');
        $result = $this->db->listCollections();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testDatabaseAggregate(): void
    {
        $col = $this->db->selectCollection('agg_test');
        $col->insertMany([['v' => 1], ['v' => 2], ['v' => 3]]);
        $cursor = $this->db->aggregate([
            ['$documents' => [['x' => 1], ['x' => 2]]],
        ]);
        $this->assertInstanceOf(ArrayCursor::class, $cursor);
    }

    public function testRenameCollection(): void
    {
        $this->db->createCollection('rename_src');
        $col = $this->db->selectCollection('rename_src');
        $col->insertOne(['x' => 1]);
        $this->db->renameCollection('rename_src', 'rename_dst');
        $names = $this->db->listCollectionNames();
        $this->assertContains('rename_dst', $names);
        $this->assertNotContains('rename_src', $names);
    }

    public function testGetPoolId(): void
    {
        $this->assertIsInt($this->db->getPoolId());
    }

    public function testDebugInfo(): void
    {
        $info = $this->db->__debugInfo();
        $this->assertArrayHasKey('databaseName', $info);
        $this->assertArrayHasKey('poolId', $info);
    }

    public function testConcernGetters(): void
    {
        $this->assertInstanceOf(ReadConcern::class, $this->db->getReadConcern());
        $this->assertInstanceOf(WriteConcern::class, $this->db->getWriteConcern());
        $this->assertInstanceOf(ReadPreference::class, $this->db->getReadPreference());
        $this->assertIsArray($this->db->getTypeMap());
    }
}
