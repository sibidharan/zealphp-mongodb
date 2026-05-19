<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use ZealPHP\MongoDB\BulkWriteResult;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\InsertManyResult;

use function array_column;
use function count;
use function extension_loaded;
use function getenv;
use function sort;
use function uniqid;

class CollectionTest extends TestCase
{
    private static Client $client;
    private Collection $col;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        self::$client = new Client(getenv('MONGODB_URI') ?: 'mongodb://db.selfmade.ninja:27017');
    }

    protected function setUp(): void
    {
        $db = self::$client->selectDatabase(getenv('MONGODB_DATABASE') ?: 'zealphp_test');
        $this->col = $db->selectCollection('phpunit_' . uniqid());
    }

    protected function tearDown(): void
    {
        try {
            $this->col->drop();
        } catch (Throwable) {
        }
    }

    public function testInsertOneAndFindOne(): void
    {
        $result = $this->col->insertOne(['name' => 'alice', 'age' => 30]);
        $this->assertTrue($result->isAcknowledged());
        $this->assertNotEmpty($result->getInsertedId());

        $doc = $this->col->findOne(['name' => 'alice']);
        $this->assertNotNull($doc);
        $this->assertSame('alice', $doc['name']);
        $this->assertSame(30, $doc['age']);
    }

    public function testInsertMany(): void
    {
        $result = $this->col->insertMany([
            ['x' => 1],
            ['x' => 2],
            ['x' => 3],
        ]);
        $this->assertInstanceOf(InsertManyResult::class, $result);
        $this->assertSame(3, $result->getInsertedCount());
        $this->assertCount(3, $result->getInsertedIds());
    }

    public function testUpdateOneWithUpsert(): void
    {
        $result = $this->col->updateOne(
            ['name' => 'bob'],
            ['$set' => ['name' => 'bob', 'age' => 25]],
            ['upsert' => true],
        );
        $this->assertSame(0, $result->getMatchedCount());

        $doc = $this->col->findOne(['name' => 'bob']);
        $this->assertNotNull($doc);
        $this->assertSame(25, $doc['age']);
    }

    public function testFindWithSortLimitSkip(): void
    {
        $this->col->insertMany([
            ['n' => 1],
            ['n' => 2],
            ['n' => 3],
            ['n' => 4],
            ['n' => 5],
        ]);
        $cursor = $this->col->find([], ['sort' => ['n' => -1], 'limit' => 2, 'skip' => 1]);
        $docs = [];
        foreach ($cursor as $doc) {
            $docs[] = $doc['n'];
        }

        $this->assertSame([4, 3], $docs);
    }

    public function testCountDocumentsAndEstimated(): void
    {
        $this->col->insertMany([['a' => 1], ['a' => 2], ['a' => 3]]);
        $this->assertSame(3, $this->col->countDocuments());
        $this->assertSame(2, $this->col->countDocuments(['a' => ['$gt' => 1]]));
        $this->assertGreaterThanOrEqual(0, $this->col->estimatedDocumentCount());
    }

    public function testDistinct(): void
    {
        $this->col->insertMany([
            ['color' => 'red'],
            ['color' => 'blue'],
            ['color' => 'red'],
        ]);
        $colors = $this->col->distinct('color');
        sort($colors);
        $this->assertSame(['blue', 'red'], $colors);
    }

    public function testAggregate(): void
    {
        $this->col->insertMany([
            ['cat' => 'A', 'v' => 1],
            ['cat' => 'B', 'v' => 2],
            ['cat' => 'A', 'v' => 3],
        ]);
        $cursor = $this->col->aggregate([
            ['$group' => ['_id' => '$cat', 'total' => ['$sum' => '$v']]],
            ['$sort' => ['_id' => 1]],
        ]);
        $results = [];
        foreach ($cursor as $doc) {
            $results[$doc['_id']] = $doc['total'];
        }

        $this->assertSame(4, $results['A']);
        $this->assertSame(2, $results['B']);
    }

    public function testBulkWrite(): void
    {
        $this->col->insertOne(['x' => 1]);
        $result = $this->col->bulkWrite([
            ['insertOne' => [['x' => 2]]],
            ['insertOne' => [['x' => 3]]],
            ['updateOne' => [['x' => 1], ['$set' => ['x' => 10]]]],
            ['deleteOne' => [['x' => 2]]],
        ]);
        $this->assertInstanceOf(BulkWriteResult::class, $result);
        $this->assertSame(2, $result->getInsertedCount());
        $this->assertSame(1, $result->getMatchedCount());
        $this->assertSame(1, $result->getDeletedCount());
    }

    public function testFindOneAndUpdateReturnAfter(): void
    {
        $this->col->insertOne(['name' => 'test', 'v' => 1]);
        $doc = $this->col->findOneAndUpdate(
            ['name' => 'test'],
            ['$set' => ['v' => 2]],
            ['returnDocument' => 2],
        );
        $this->assertNotNull($doc);
        $this->assertSame(2, $doc['v']);
    }

    public function testCreateAndListIndexes(): void
    {
        $name = $this->col->createIndex(['field' => 1], ['unique' => true]);
        $this->assertIsString($name);

        $indexes = $this->col->listIndexes();
        $this->assertGreaterThanOrEqual(2, count($indexes));
    }

    public function testDropIndex(): void
    {
        $name = $this->col->createIndex(['tmp' => 1]);
        $this->col->dropIndex($name);
        $indexes = $this->col->listIndexes();
        $names = array_column($indexes, 'name');
        $this->assertNotContains($name, $names);
    }

    public function testCountAlias(): void
    {
        $this->col->insertMany([['a' => 1], ['a' => 2]]);
        $this->assertSame(2, $this->col->count());
        $this->assertSame(1, $this->col->count(['a' => 1]));
    }

    public function testWithOptions(): void
    {
        $new = $this->col->withOptions(['readConcern' => 'majority']);
        $this->assertInstanceOf(Collection::class, $new);
        $this->assertNotSame($this->col, $new);
    }

    public function testFindOneProjection(): void
    {
        $this->col->insertOne(['name' => 'proj', 'secret' => 'hide']);
        $doc = $this->col->findOne(['name' => 'proj'], ['projection' => ['name' => 1, '_id' => 0]]);
        $this->assertSame('proj', $doc['name']);
        $this->assertArrayNotHasKey('secret', (array) $doc);
        $this->assertArrayNotHasKey('_id', (array) $doc);
    }

    public function testDeleteMany(): void
    {
        $this->col->insertMany([['d' => 1], ['d' => 1], ['d' => 2]]);
        $result = $this->col->deleteMany(['d' => 1]);
        $this->assertSame(2, $result->getDeletedCount());
        $this->assertSame(1, $this->col->countDocuments());
    }

    public function testReplaceOne(): void
    {
        $this->col->insertOne(['name' => 'old', 'v' => 1]);
        $result = $this->col->replaceOne(['name' => 'old'], ['name' => 'new', 'v' => 2]);
        $this->assertSame(1, $result->getModifiedCount());
        $doc = $this->col->findOne(['name' => 'new']);
        $this->assertSame(2, $doc['v']);
    }
}
