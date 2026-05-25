<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\WriteConcern;

class CollectionUnitTest extends TestCase
{
    private Collection $collection;

    protected function setUp(): void
    {
        $this->collection = new Collection(10, 'test_db', 'users');
    }

    public function testConstructorWithAllParameters(): void
    {
        $col = new Collection(5, 'mydb', 'orders', ['readConcern' => 'majority']);
        $this->assertSame('orders', $col->getCollectionName());
        $this->assertSame('mydb', $col->getDatabaseName());
        $this->assertSame('mydb.orders', $col->getNamespace());
    }

    public function testGetCollectionName(): void
    {
        $this->assertSame('users', $this->collection->getCollectionName());
    }

    public function testGetDatabaseName(): void
    {
        $this->assertSame('test_db', $this->collection->getDatabaseName());
    }

    public function testGetNamespaceReturnsDotSeparated(): void
    {
        $this->assertSame('test_db.users', $this->collection->getNamespace());
    }

    public function testNamespaceFormatWithSpecialCharacters(): void
    {
        $col = new Collection(1, 'my-db_v2', 'system.users');
        $this->assertSame('my-db_v2.system.users', $col->getNamespace());
    }

    public function testNamespaceWithUnicodeCharacters(): void
    {
        $col = new Collection(1, 'db', 'coll_évènements');
        $this->assertSame("db.coll_\u{00e9}v\u{00e8}nements", $col->getNamespace());
    }

    public function testWithOptionsReturnsNewCollection(): void
    {
        $newCol = $this->collection->withOptions(['readConcern' => 'local']);
        $this->assertInstanceOf(Collection::class, $newCol);
        $this->assertNotSame($this->collection, $newCol);
    }

    public function testWithOptionsMergesOptions(): void
    {
        $col1 = new Collection(1, 'db', 'col', ['a' => 1, 'b' => 2]);
        $col2 = $col1->withOptions(['b' => 99, 'c' => 3]);
        // Both should share the same identity fields
        $this->assertSame('db.col', $col2->getNamespace());
        $this->assertSame($col1->getCollectionName(), $col2->getCollectionName());
        $this->assertSame($col1->getDatabaseName(), $col2->getDatabaseName());
    }

    public function testWithOptionsPreservesOriginal(): void
    {
        $original = new Collection(7, 'orig_db', 'orig_col', ['key' => 'value']);
        $cloned = $original->withOptions(['key' => 'overridden', 'extra' => true]);

        $this->assertSame('orig_db', $original->getDatabaseName());
        $this->assertSame('orig_col', $original->getCollectionName());
        $this->assertSame('orig_db.orig_col', $original->getNamespace());
        $this->assertNotSame($original, $cloned);
    }

    public function testWithOptionsChaining(): void
    {
        $col1 = $this->collection->withOptions(['a' => 1]);
        $col2 = $col1->withOptions(['b' => 2]);
        $this->assertNotSame($col1, $col2);
        $this->assertSame($col1->getNamespace(), $col2->getNamespace());
    }

    public function testGetReadConcernDefault(): void
    {
        $rc = $this->collection->getReadConcern();
        $this->assertInstanceOf(ReadConcern::class, $rc);
        $this->assertNull($rc->getLevel());
        $this->assertTrue($rc->isDefault());
    }

    public function testGetWriteConcernDefault(): void
    {
        $wc = $this->collection->getWriteConcern();
        $this->assertInstanceOf(WriteConcern::class, $wc);
        $this->assertSame(1, $wc->getW());
        $this->assertSame(0, $wc->getWtimeout());
        $this->assertNull($wc->getJournal());
    }

    public function testGetReadPreferenceDefault(): void
    {
        $rp = $this->collection->getReadPreference();
        $this->assertInstanceOf(ReadPreference::class, $rp);
        $this->assertSame(ReadPreference::PRIMARY, $rp->getModeString());
        $this->assertSame('primary', $rp->getModeString());
    }

    public function testGetTypeMapReturnsBSONClasses(): void
    {
        $typeMap = $this->collection->getTypeMap();
        $this->assertIsArray($typeMap);
        $this->assertSame(BSONDocument::class, $typeMap['root']);
        $this->assertSame(BSONDocument::class, $typeMap['document']);
        $this->assertSame(BSONArray::class, $typeMap['array']);
    }

    public function testGetTypeMapKeysAreComplete(): void
    {
        $typeMap = $this->collection->getTypeMap();
        $this->assertArrayHasKey('root', $typeMap);
        $this->assertArrayHasKey('document', $typeMap);
        $this->assertArrayHasKey('array', $typeMap);
        $this->assertCount(3, $typeMap);
    }

    public function testMultipleCollectionsSharePoolId(): void
    {
        $col1 = new Collection(42, 'db', 'col_a');
        $col2 = new Collection(42, 'db', 'col_b');
        $this->assertSame('db.col_a', $col1->getNamespace());
        $this->assertSame('db.col_b', $col2->getNamespace());
    }

    public function testEmptyOptionsDoNotAffectBehavior(): void
    {
        $col = new Collection(1, 'db', 'col', []);
        $this->assertSame('db', $col->getDatabaseName());
        $this->assertSame('col', $col->getCollectionName());
        $this->assertSame('db.col', $col->getNamespace());
    }

    public function testConcernGettersReturnNewInstancesEachCall(): void
    {
        $rc1 = $this->collection->getReadConcern();
        $rc2 = $this->collection->getReadConcern();
        $this->assertNotSame($rc1, $rc2);
        $this->assertEquals($rc1, $rc2);

        $wc1 = $this->collection->getWriteConcern();
        $wc2 = $this->collection->getWriteConcern();
        $this->assertNotSame($wc1, $wc2);
        $this->assertEquals($wc1, $wc2);

        $rp1 = $this->collection->getReadPreference();
        $rp2 = $this->collection->getReadPreference();
        $this->assertNotSame($rp1, $rp2);
        $this->assertEquals($rp1, $rp2);
    }
}
