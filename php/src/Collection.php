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
        return zealphp_mongodb_find_one($this->poolId, $this->dbName, $this->colName, (array)$filter, $options ?: null);
    }

    public function find(array|object $filter = [], array $options = []): Cursor
    {
        $cursorId = zealphp_mongodb_find($this->poolId, $this->dbName, $this->colName, (array)$filter, $options ?: null);
        return new Cursor($cursorId);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        $result = zealphp_mongodb_insert_one($this->poolId, $this->dbName, $this->colName, (array)$document, $options ?: null);
        return new InsertOneResult($result);
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $result = zealphp_mongodb_update_one($this->poolId, $this->dbName, $this->colName, (array)$filter, (array)$update, $options ?: null);
        return new UpdateResult($result);
    }

    public function deleteOne(array|object $filter, array $options = []): DeleteResult
    {
        $result = zealphp_mongodb_delete_one($this->poolId, $this->dbName, $this->colName, (array)$filter, $options ?: null);
        return new DeleteResult($result);
    }

    public function countDocuments(array|object $filter = [], array $options = []): int
    {
        return zealphp_mongodb_count_documents($this->poolId, $this->dbName, $this->colName, (array)$filter, $options ?: null);
    }

    public function aggregate(array $pipeline, array $options = []): Cursor
    {
        $cursorId = zealphp_mongodb_aggregate($this->poolId, $this->dbName, $this->colName, $pipeline, $options ?: null);
        return new Cursor($cursorId);
    }

    public function getCollectionName(): string { return $this->colName; }
    public function getDatabaseName(): string { return $this->dbName; }
    public function getNamespace(): string { return $this->dbName . '.' . $this->colName; }
}
