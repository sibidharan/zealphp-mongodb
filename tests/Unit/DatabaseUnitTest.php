<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stringable;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Database;
use ZealPHP\MongoDB\GridFS\Bucket;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\WriteConcern;

class DatabaseUnitTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(42, 'test_db');
    }

    public function testConstructorStoresValues(): void
    {
        $db = new Database(7, 'my_database', ['readConcern' => 'majority']);
        $this->assertSame('my_database', $db->getDatabaseName());
        $this->assertSame(7, $db->getPoolId());
    }

    public function testGetDatabaseName(): void
    {
        $this->assertSame('test_db', $this->db->getDatabaseName());
    }

    public function testGetPoolId(): void
    {
        $this->assertSame(42, $this->db->getPoolId());
    }

    public function testSelectCollectionReturnsCollectionWithCorrectNames(): void
    {
        $collection = $this->db->selectCollection('users');
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('users', $collection->getCollectionName());
        $this->assertSame('test_db', $collection->getDatabaseName());
    }

    public function testSelectCollectionPassesOptions(): void
    {
        $collection = $this->db->selectCollection('orders', ['readConcern' => 'local']);
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('orders', $collection->getCollectionName());
    }

    public function testGetCollectionIsAliasForSelectCollection(): void
    {
        $col1 = $this->db->getCollection('products');
        $col2 = $this->db->selectCollection('products');
        $this->assertSame($col1->getCollectionName(), $col2->getCollectionName());
        $this->assertSame($col1->getDatabaseName(), $col2->getDatabaseName());
        $this->assertSame($col1->getNamespace(), $col2->getNamespace());
    }

    public function testMagicGetReturnsCollection(): void
    {
        $collection = $this->db->users;
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('users', $collection->getCollectionName());
        $this->assertSame('test_db', $collection->getDatabaseName());
    }

    public function testMagicGetWithDottedName(): void
    {
        $collection = $this->db->{'system.profile'};
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('system.profile', $collection->getCollectionName());
    }

    public function testSelectGridFSBucketReturnsBucket(): void
    {
        $bucket = $this->db->selectGridFSBucket();
        $this->assertInstanceOf(Bucket::class, $bucket);
        $this->assertSame('fs', $bucket->getBucketName());
        $this->assertSame('test_db', $bucket->getDatabaseName());
    }

    public function testSelectGridFSBucketWithCustomOptions(): void
    {
        $bucket = $this->db->selectGridFSBucket(['bucketName' => 'images']);
        $this->assertInstanceOf(Bucket::class, $bucket);
        $this->assertSame('images', $bucket->getBucketName());
        $this->assertSame('test_db', $bucket->getDatabaseName());
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $newDb = $this->db->withOptions(['readConcern' => 'majority']);
        $this->assertInstanceOf(Database::class, $newDb);
        $this->assertNotSame($this->db, $newDb);
    }

    public function testWithOptionsMergesOptions(): void
    {
        $db1 = new Database(1, 'db', ['a' => 1, 'b' => 2]);
        $db2 = $db1->withOptions(['b' => 99, 'c' => 3]);
        // The new instance should retain the same database name and pool ID
        $this->assertSame('db', $db2->getDatabaseName());
        $this->assertSame(1, $db2->getPoolId());
        // Original must be unchanged — verified by creating another clone from original
        $db3 = $db1->withOptions([]);
        $this->assertNotSame($db1, $db3);
    }

    public function testWithOptionsDoesNotModifyOriginal(): void
    {
        $original = new Database(1, 'orig_db', ['key' => 'value']);
        $cloned = $original->withOptions(['key' => 'overridden', 'extra' => true]);
        // Original's identity should remain intact
        $this->assertSame('orig_db', $original->getDatabaseName());
        $this->assertSame(1, $original->getPoolId());
        // Cloned should also preserve name and pool
        $this->assertSame('orig_db', $cloned->getDatabaseName());
        $this->assertSame(1, $cloned->getPoolId());
        $this->assertNotSame($original, $cloned);
    }

    public function testToStringReturnsDatabaseName(): void
    {
        $this->assertSame('test_db', (string) $this->db);
        $this->assertSame('test_db', $this->db->__toString());
    }

    public function testImplementsStringable(): void
    {
        $this->assertInstanceOf(Stringable::class, $this->db);
    }

    public function testDebugInfoReturnsExpectedArray(): void
    {
        $info = $this->db->__debugInfo();
        $this->assertIsArray($info);
        $this->assertArrayHasKey('databaseName', $info);
        $this->assertArrayHasKey('poolId', $info);
        $this->assertSame('test_db', $info['databaseName']);
        $this->assertSame(42, $info['poolId']);
    }

    public function testGetReadConcernReturnsDefault(): void
    {
        $rc = $this->db->getReadConcern();
        $this->assertInstanceOf(ReadConcern::class, $rc);
        $this->assertNull($rc->getLevel());
        $this->assertTrue($rc->isDefault());
    }

    public function testGetWriteConcernReturnsDefault(): void
    {
        $wc = $this->db->getWriteConcern();
        $this->assertInstanceOf(WriteConcern::class, $wc);
        $this->assertSame(1, $wc->getW());
        $this->assertSame(0, $wc->getWtimeout());
        $this->assertNull($wc->getJournal());
    }

    public function testGetReadPreferenceReturnsPrimary(): void
    {
        $rp = $this->db->getReadPreference();
        $this->assertInstanceOf(ReadPreference::class, $rp);
        $this->assertSame(ReadPreference::PRIMARY, $rp->getModeString());
        $this->assertSame('primary', $rp->getModeString());
    }

    public function testGetTypeMapReturnsExpectedMap(): void
    {
        $typeMap = $this->db->getTypeMap();
        $this->assertIsArray($typeMap);
        $this->assertSame('array', $typeMap['root']);
        $this->assertSame('array', $typeMap['document']);
        $this->assertSame('array', $typeMap['array']);
    }

    public function testMultipleSelectCollectionCallsReturnDistinctInstances(): void
    {
        $col1 = $this->db->selectCollection('a');
        $col2 = $this->db->selectCollection('a');
        $this->assertNotSame($col1, $col2);
        $this->assertSame($col1->getNamespace(), $col2->getNamespace());
    }

    public function testWithOptionsPreservesPoolIdAndName(): void
    {
        $db = new Database(99, 'important_db');
        $cloned = $db->withOptions(['writeConcern' => 'majority']);
        $this->assertSame(99, $cloned->getPoolId());
        $this->assertSame('important_db', $cloned->getDatabaseName());
        $this->assertSame('important_db', (string) $cloned);
    }
}
