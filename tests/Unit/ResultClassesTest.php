<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BulkWriteResult;
use ZealPHP\MongoDB\DeleteResult;
use ZealPHP\MongoDB\InsertManyResult;
use ZealPHP\MongoDB\InsertOneResult;
use ZealPHP\MongoDB\UpdateResult;

class ResultClassesTest extends TestCase
{
    // ---------------------------------------------------------------
    // InsertOneResult
    // ---------------------------------------------------------------

    public function testInsertOneResultGetInsertedCount(): void
    {
        $result = new InsertOneResult(['inserted_count' => 1]);
        $this->assertSame(1, $result->getInsertedCount());
    }

    public function testInsertOneResultGetInsertedCountDefaultsToZero(): void
    {
        $result = new InsertOneResult([]);
        $this->assertSame(0, $result->getInsertedCount());
    }

    public function testInsertOneResultGetInsertedIdString(): void
    {
        $result = new InsertOneResult(['inserted_id' => 'abc123']);
        $this->assertSame('abc123', $result->getInsertedId());
    }

    public function testInsertOneResultGetInsertedIdNull(): void
    {
        $result = new InsertOneResult([]);
        $this->assertNull($result->getInsertedId());
    }

    public function testInsertOneResultGetInsertedIdWrapsOidArray(): void
    {
        $oid = '507f1f77bcf86cd799439011';
        $result = new InsertOneResult(['inserted_id' => ['$oid' => $oid]]);
        $id = $result->getInsertedId();

        $this->assertInstanceOf(ObjectId::class, $id);
        $this->assertSame($oid, (string) $id);
    }

    public function testInsertOneResultGetInsertedIdWrapsGenericArray(): void
    {
        // A non-special array goes through wrapDoc and becomes a BSONDocument
        $result = new InsertOneResult(['inserted_id' => ['foo' => 'bar']]);
        $id = $result->getInsertedId();

        $this->assertInstanceOf(BSONDocument::class, $id);
    }

    public function testInsertOneResultIsAcknowledgedDefaultsToTrue(): void
    {
        $result = new InsertOneResult([]);
        $this->assertTrue($result->isAcknowledged());
    }

    public function testInsertOneResultIsAcknowledgedFalse(): void
    {
        $result = new InsertOneResult(['acknowledged' => false]);
        $this->assertFalse($result->isAcknowledged());
    }

    public function testInsertOneResultIsAcknowledgedExplicitTrue(): void
    {
        $result = new InsertOneResult(['acknowledged' => true]);
        $this->assertTrue($result->isAcknowledged());
    }

    // ---------------------------------------------------------------
    // InsertManyResult
    // ---------------------------------------------------------------

    public function testInsertManyResultGetInsertedCount(): void
    {
        $result = new InsertManyResult(['inserted_count' => 5]);
        $this->assertSame(5, $result->getInsertedCount());
    }

    public function testInsertManyResultGetInsertedCountDefaultsToZero(): void
    {
        $result = new InsertManyResult([]);
        $this->assertSame(0, $result->getInsertedCount());
    }

    public function testInsertManyResultGetInsertedIdsEmpty(): void
    {
        $result = new InsertManyResult([]);
        $this->assertSame([], $result->getInsertedIds());
    }

    public function testInsertManyResultGetInsertedIdsWithStringIds(): void
    {
        $result = new InsertManyResult(['inserted_ids' => ['id1', 'id2', 'id3']]);
        $this->assertSame(['id1', 'id2', 'id3'], $result->getInsertedIds());
    }

    public function testInsertManyResultGetInsertedIdsWithOidArrays(): void
    {
        $oid1 = '507f1f77bcf86cd799439011';
        $oid2 = '507f1f77bcf86cd799439022';
        $result = new InsertManyResult([
            'inserted_ids' => [
                ['$oid' => $oid1],
                ['$oid' => $oid2],
            ],
        ]);
        $ids = $result->getInsertedIds();

        $this->assertCount(2, $ids);
        $this->assertInstanceOf(ObjectId::class, $ids[0]);
        $this->assertInstanceOf(ObjectId::class, $ids[1]);
        $this->assertSame($oid1, (string) $ids[0]);
        $this->assertSame($oid2, (string) $ids[1]);
    }

    public function testInsertManyResultGetInsertedIdsMixed(): void
    {
        $oid = '507f1f77bcf86cd799439011';
        $result = new InsertManyResult([
            'inserted_ids' => [
                ['$oid' => $oid],
                'plain-string-id',
                42,
            ],
        ]);
        $ids = $result->getInsertedIds();

        $this->assertCount(3, $ids);
        $this->assertInstanceOf(ObjectId::class, $ids[0]);
        $this->assertSame($oid, (string) $ids[0]);
        $this->assertSame('plain-string-id', $ids[1]);
        $this->assertSame(42, $ids[2]);
    }

    public function testInsertManyResultIsAcknowledgedDefaultsToTrue(): void
    {
        $result = new InsertManyResult([]);
        $this->assertTrue($result->isAcknowledged());
    }

    public function testInsertManyResultIsAcknowledgedFalse(): void
    {
        $result = new InsertManyResult(['acknowledged' => false]);
        $this->assertFalse($result->isAcknowledged());
    }

    // ---------------------------------------------------------------
    // UpdateResult
    // ---------------------------------------------------------------

    public function testUpdateResultGetMatchedCount(): void
    {
        $result = new UpdateResult(['matched_count' => 3]);
        $this->assertSame(3, $result->getMatchedCount());
    }

    public function testUpdateResultGetMatchedCountDefaultsToZero(): void
    {
        $result = new UpdateResult([]);
        $this->assertSame(0, $result->getMatchedCount());
    }

    public function testUpdateResultGetModifiedCount(): void
    {
        $result = new UpdateResult(['modified_count' => 2]);
        $this->assertSame(2, $result->getModifiedCount());
    }

    public function testUpdateResultGetModifiedCountDefaultsToZero(): void
    {
        $result = new UpdateResult([]);
        $this->assertSame(0, $result->getModifiedCount());
    }

    public function testUpdateResultGetUpsertedCount(): void
    {
        $result = new UpdateResult(['upserted_count' => 1]);
        $this->assertSame(1, $result->getUpsertedCount());
    }

    public function testUpdateResultGetUpsertedCountDefaultsToZero(): void
    {
        $result = new UpdateResult([]);
        $this->assertSame(0, $result->getUpsertedCount());
    }

    public function testUpdateResultGetUpsertedIdNull(): void
    {
        $result = new UpdateResult([]);
        $this->assertNull($result->getUpsertedId());
    }

    public function testUpdateResultGetUpsertedIdWrapsOidArray(): void
    {
        $oid = '507f1f77bcf86cd799439099';
        $result = new UpdateResult(['upserted_id' => ['$oid' => $oid]]);
        $id = $result->getUpsertedId();

        $this->assertInstanceOf(ObjectId::class, $id);
        $this->assertSame($oid, (string) $id);
    }

    public function testUpdateResultGetUpsertedIdString(): void
    {
        $result = new UpdateResult(['upserted_id' => 'some-string-id']);
        $this->assertSame('some-string-id', $result->getUpsertedId());
    }

    public function testUpdateResultIsAcknowledgedDefaultsToTrue(): void
    {
        $result = new UpdateResult([]);
        $this->assertTrue($result->isAcknowledged());
    }

    public function testUpdateResultIsAcknowledgedFalse(): void
    {
        $result = new UpdateResult(['acknowledged' => false]);
        $this->assertFalse($result->isAcknowledged());
    }

    public function testUpdateResultFullPayload(): void
    {
        $result = new UpdateResult([
            'matched_count' => 10,
            'modified_count' => 7,
            'upserted_count' => 0,
            'upserted_id' => null,
            'acknowledged' => true,
        ]);

        $this->assertSame(10, $result->getMatchedCount());
        $this->assertSame(7, $result->getModifiedCount());
        $this->assertSame(0, $result->getUpsertedCount());
        $this->assertNull($result->getUpsertedId());
        $this->assertTrue($result->isAcknowledged());
    }

    // ---------------------------------------------------------------
    // DeleteResult
    // ---------------------------------------------------------------

    public function testDeleteResultGetDeletedCount(): void
    {
        $result = new DeleteResult(['deleted_count' => 4]);
        $this->assertSame(4, $result->getDeletedCount());
    }

    public function testDeleteResultGetDeletedCountDefaultsToZero(): void
    {
        $result = new DeleteResult([]);
        $this->assertSame(0, $result->getDeletedCount());
    }

    public function testDeleteResultIsAcknowledgedDefaultsToTrue(): void
    {
        $result = new DeleteResult([]);
        $this->assertTrue($result->isAcknowledged());
    }

    public function testDeleteResultIsAcknowledgedFalse(): void
    {
        $result = new DeleteResult(['acknowledged' => false]);
        $this->assertFalse($result->isAcknowledged());
    }

    // ---------------------------------------------------------------
    // BulkWriteResult
    // ---------------------------------------------------------------

    public function testBulkWriteResultGetInsertedCount(): void
    {
        $result = new BulkWriteResult(['inserted_count' => 3]);
        $this->assertSame(3, $result->getInsertedCount());
    }

    public function testBulkWriteResultGetMatchedCount(): void
    {
        $result = new BulkWriteResult(['matched_count' => 5]);
        $this->assertSame(5, $result->getMatchedCount());
    }

    public function testBulkWriteResultGetModifiedCount(): void
    {
        $result = new BulkWriteResult(['modified_count' => 2]);
        $this->assertSame(2, $result->getModifiedCount());
    }

    public function testBulkWriteResultGetDeletedCount(): void
    {
        $result = new BulkWriteResult(['deleted_count' => 1]);
        $this->assertSame(1, $result->getDeletedCount());
    }

    public function testBulkWriteResultGetUpsertedCount(): void
    {
        $result = new BulkWriteResult(['upserted_count' => 4]);
        $this->assertSame(4, $result->getUpsertedCount());
    }

    public function testBulkWriteResultGetUpsertedIds(): void
    {
        $result = new BulkWriteResult(['upserted_ids' => ['id1', 'id2']]);
        $this->assertSame(['id1', 'id2'], $result->getUpsertedIds());
    }

    public function testBulkWriteResultGetUpsertedIdsDefaultsToEmpty(): void
    {
        $result = new BulkWriteResult([]);
        $this->assertSame([], $result->getUpsertedIds());
    }

    public function testBulkWriteResultIsAcknowledgedDefaultsToTrue(): void
    {
        $result = new BulkWriteResult([]);
        $this->assertTrue($result->isAcknowledged());
    }

    public function testBulkWriteResultIsAcknowledgedFalse(): void
    {
        $result = new BulkWriteResult(['acknowledged' => false]);
        $this->assertFalse($result->isAcknowledged());
    }

    public function testBulkWriteResultAllDefaultsWithEmptyArray(): void
    {
        $result = new BulkWriteResult([]);

        $this->assertSame(0, $result->getInsertedCount());
        $this->assertSame(0, $result->getMatchedCount());
        $this->assertSame(0, $result->getModifiedCount());
        $this->assertSame(0, $result->getDeletedCount());
        $this->assertSame(0, $result->getUpsertedCount());
        $this->assertSame([], $result->getUpsertedIds());
        $this->assertTrue($result->isAcknowledged());
    }

    public function testBulkWriteResultFullPayload(): void
    {
        $result = new BulkWriteResult([
            'inserted_count' => 10,
            'matched_count' => 20,
            'modified_count' => 15,
            'deleted_count' => 5,
            'upserted_count' => 3,
            'upserted_ids' => ['a', 'b', 'c'],
            'acknowledged' => true,
        ]);

        $this->assertSame(10, $result->getInsertedCount());
        $this->assertSame(20, $result->getMatchedCount());
        $this->assertSame(15, $result->getModifiedCount());
        $this->assertSame(5, $result->getDeletedCount());
        $this->assertSame(3, $result->getUpsertedCount());
        $this->assertSame(['a', 'b', 'c'], $result->getUpsertedIds());
        $this->assertTrue($result->isAcknowledged());
    }
}
