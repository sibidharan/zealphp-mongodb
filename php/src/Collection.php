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
        // Use async bridge (eventfd + Event::add) in coroutine mode
        return AsyncBridge::findOneAsync($this->poolId, $this->dbName, $this->colName, (array)$filter);
    }

    public function find(array|object $filter = [], array $options = []): Cursor
    {
        $opts = $options ?: null;
        $cursorId = zealphp_mongodb_find($this->poolId, $this->dbName, $this->colName, (array)$filter, $opts);
        return new Cursor($cursorId);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        $opts = $options ?: null;
        $result = zealphp_mongodb_insert_one($this->poolId, $this->dbName, $this->colName, (array)$document, $opts);
        return new InsertOneResult($result);
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $opts = $options ?: null;
        $result = zealphp_mongodb_update_one($this->poolId, $this->dbName, $this->colName, (array)$filter, (array)$update, $opts);
        return new UpdateResult($result);
    }

    public function deleteOne(array|object $filter, array $options = []): DeleteResult
    {
        $opts = $options ?: null;
        $result = zealphp_mongodb_delete_one($this->poolId, $this->dbName, $this->colName, (array)$filter, $opts);
        return new DeleteResult($result);
    }

    public function countDocuments(array|object $filter = [], array $options = []): int
    {
        $opts = $options ?: null;
        return zealphp_mongodb_count_documents($this->poolId, $this->dbName, $this->colName, (array)$filter, $opts);
    }

    public function aggregate(array $pipeline, array $options = []): Cursor
    {
        $opts = $options ?: null;
        $cursorId = zealphp_mongodb_aggregate($this->poolId, $this->dbName, $this->colName, $pipeline, $opts);
        return new Cursor($cursorId);
    }

    public function getCollectionName(): string { return $this->colName; }
    public function getDatabaseName(): string { return $this->dbName; }
    public function getNamespace(): string { return $this->dbName . '.' . $this->colName; }
}
