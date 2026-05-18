<?php
namespace ZealPHP\MongoDB;

class Collection
{
    public function __construct(
        private int $poolId,
        private string $dbName,
        private string $colName,
        private array $options = []
    ) {}

    public function findOne(array|object $filter = [], array $options = []): ?array
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $f = (array)$filter; $o = $options ?: null;
        return AsyncBridge::run(fn() => zealphp_mongodb_find_one($p, $d, $c, $f, $o));
    }

    public function find(array|object $filter = [], array $options = []): Cursor
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $f = (array)$filter; $o = $options ?: null;
        $cursorId = AsyncBridge::run(fn() => zealphp_mongodb_find($p, $d, $c, $f, $o));
        return new Cursor($cursorId);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $doc = (array)$document; $o = $options ?: null;
        $result = AsyncBridge::run(fn() => zealphp_mongodb_insert_one($p, $d, $c, $doc, $o));
        return new InsertOneResult($result);
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $f = (array)$filter; $u = (array)$update; $o = $options ?: null;
        $result = AsyncBridge::run(fn() => zealphp_mongodb_update_one($p, $d, $c, $f, $u, $o));
        return new UpdateResult($result);
    }

    public function deleteOne(array|object $filter, array $options = []): DeleteResult
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $f = (array)$filter; $o = $options ?: null;
        $result = AsyncBridge::run(fn() => zealphp_mongodb_delete_one($p, $d, $c, $f, $o));
        return new DeleteResult($result);
    }

    public function countDocuments(array|object $filter = [], array $options = []): int
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $f = (array)$filter; $o = $options ?: null;
        return AsyncBridge::run(fn() => zealphp_mongodb_count_documents($p, $d, $c, $f, $o));
    }

    public function aggregate(array $pipeline, array $options = []): Cursor
    {
        $p = $this->poolId; $d = $this->dbName; $c = $this->colName;
        $o = $options ?: null;
        $cursorId = AsyncBridge::run(fn() => zealphp_mongodb_aggregate($p, $d, $c, $pipeline, $o));
        return new Cursor($cursorId);
    }

    public function getCollectionName(): string { return $this->colName; }
    public function getDatabaseName(): string { return $this->dbName; }
    public function getNamespace(): string { return $this->dbName . '.' . $this->colName; }
}
