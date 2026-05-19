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

    public function findOne(array|object $filter = [], array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            if ($options) $filter['__options'] = $options;
            return self::wrapDoc(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'find_one', $filter));
        }
        $opts = $options ?: null;
        return self::wrapDoc(zealphp_mongodb_find_one($this->poolId, $this->dbName, $this->colName, $filter, $opts));
    }

    public function find(array|object $filter = [], array $options = []): Cursor|ArrayCursor
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            $opts = self::prepareBSON($options);
            $result = AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'find', $filter, $opts ?: null);
            return new ArrayCursor($result ?? []);
        }
        $opts = $options ?: null;
        $cursorId = zealphp_mongodb_find($this->poolId, $this->dbName, $this->colName, $filter, $opts);
        return new Cursor($cursorId);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        $document = self::prepareBSON((array)$document);
        if (AsyncBridge::isCoroutineMode()) {
            return new InsertOneResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'insert_one', $document) ?? []);
        }
        $opts = null;
        return new InsertOneResult(zealphp_mongodb_insert_one($this->poolId, $this->dbName, $this->colName, $document, $opts));
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array)$filter);
        $update = self::prepareBSON((array)$update);
        if (AsyncBridge::isCoroutineMode()) {
            if ($options) $filter['__options'] = $options;
            return new UpdateResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'update_one', $filter, $update));
        }
        $opts = $options ?: null;
        return new UpdateResult(zealphp_mongodb_update_one($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));
    }

    public function updateMany(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array)$filter);
        $update = self::prepareBSON((array)$update);
        if (AsyncBridge::isCoroutineMode()) {
            if ($options) $filter['__options'] = $options;
            return new UpdateResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'update_many', $filter, $update));
        }
        $opts = $options ?: null;
        return new UpdateResult(zealphp_mongodb_update_many($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));
    }

    public function deleteOne(array|object $filter, array $options = []): DeleteResult
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            return new DeleteResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'delete_one', $filter));
        }
        $opts = null;
        return new DeleteResult(zealphp_mongodb_delete_one($this->poolId, $this->dbName, $this->colName, $filter, $opts));
    }

    public function deleteMany(array|object $filter, array $options = []): DeleteResult
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            return new DeleteResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'delete_many', $filter));
        }
        $opts = null;
        return new DeleteResult(zealphp_mongodb_delete_many($this->poolId, $this->dbName, $this->colName, $filter, $opts));
    }

    public function replaceOne(array|object $filter, array|object $replacement, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array)$filter);
        $replacement = self::prepareBSON((array)$replacement);
        if (AsyncBridge::isCoroutineMode()) {
            if ($options) $filter['__options'] = $options;
            return new UpdateResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'replace_one', $filter, $replacement));
        }
        $opts = $options ?: null;
        return new UpdateResult(zealphp_mongodb_replace_one($this->poolId, $this->dbName, $this->colName, $filter, $replacement, $opts));
    }

    public function countDocuments(array|object $filter = [], array $options = []): int
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            $result = AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'count_documents', $filter);
            return $result['count'] ?? 0;
        }
        $opts = null;
        return zealphp_mongodb_count_documents($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function distinct(string $fieldName, array|object $filter = [], array $options = []): array
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            $filterWithField = array_merge($filter, ['__field' => $fieldName]);
            $result = AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'distinct', $filterWithField);
            return $result ?? [];
        }
        $opts = null;
        return zealphp_mongodb_distinct($this->poolId, $this->dbName, $this->colName, $fieldName, $filter, $opts);
    }

    public function aggregate(array $pipeline, array $options = []): Cursor|ArrayCursor
    {
        $pipeline = self::prepareBSON($pipeline);
        if (AsyncBridge::isCoroutineMode()) {
            $result = AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'aggregate', [], $pipeline);
            return new ArrayCursor($result ?? []);
        }
        $opts = $options ?: null;
        $cursorId = zealphp_mongodb_aggregate($this->poolId, $this->dbName, $this->colName, $pipeline, $opts);
        return new Cursor($cursorId);
    }

    public function findOneAndUpdate(array|object $filter, array|object $update, array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array)$filter);
        $update = self::prepareBSON((array)$update);
        if (AsyncBridge::isCoroutineMode()) {
            if ($options) $filter['__options'] = $options;
            return self::wrapDoc(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'find_one_and_update', $filter, $update));
        }
        $opts = $options ?: null;
        return self::wrapDoc(zealphp_mongodb_find_one_and_update($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));
    }

    public function findOneAndDelete(array|object $filter, array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array)$filter);
        if (AsyncBridge::isCoroutineMode()) {
            return self::wrapDoc(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'find_one_and_delete', $filter));
        }
        $opts = null;
        return self::wrapDoc(zealphp_mongodb_find_one_and_delete($this->poolId, $this->dbName, $this->colName, $filter, $opts));
    }

    public function findOneAndReplace(array|object $filter, array|object $replacement, array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array)$filter);
        $replacement = self::prepareBSON((array)$replacement);
        if (AsyncBridge::isCoroutineMode()) {
            if ($options) $filter['__options'] = $options;
            return self::wrapDoc(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'find_one_and_replace', $filter, $replacement));
        }
        $opts = $options ?: null;
        return self::wrapDoc(zealphp_mongodb_find_one_and_replace($this->poolId, $this->dbName, $this->colName, $filter, $replacement, $opts));
    }

    public function createIndex(array|object $key, array $options = []): string
    {
        $key = self::prepareBSON((array)$key);
        $opts = $options ?: null;
        return zealphp_mongodb_create_index($this->poolId, $this->dbName, $this->colName, $key, $opts);
    }

    public function listIndexes(array $options = []): array
    {
        return [];
    }

    public function dropIndex(string $indexName, array $options = []): void
    {
    }

    public function count(array|object $filter = [], array $options = []): int
    {
        return $this->countDocuments($filter, $options);
    }

    public function getCollectionName(): string { return $this->colName; }
    public function getDatabaseName(): string { return $this->dbName; }
    public function getNamespace(): string { return $this->dbName . '.' . $this->colName; }

    private ?ReadConcern $readConcern = null;
    private ?WriteConcern $writeConcern = null;
    private ?ReadPreference $readPreference = null;

    public function getReadConcern(): ReadConcern { return $this->readConcern ?? new ReadConcern(); }
    public function getWriteConcern(): WriteConcern { return $this->writeConcern ?? new WriteConcern(1); }
    public function getReadPreference(): ReadPreference { return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY); }
    public function getTypeMap(): array { return ['root' => 'array', 'document' => 'array', 'array' => 'array']; }

    public static function wrapDoc(mixed $data): mixed
    {
        if ($data === null) return null;
        if (!is_array($data)) return $data;
        if (array_is_list($data)) {
            return array_map([self::class, 'wrapDoc'], $data);
        }
        $wrapped = new Document();
        foreach ($data as $key => $value) {
            $wrapped[$key] = is_array($value) ? self::wrapDoc($value) : $value;
        }
        return $wrapped;
    }

    private static function prepareBSON(mixed $data): mixed
    {
        if ($data instanceof \MongoDB\BSON\ObjectId) {
            return ['$oid' => (string)$data];
        }
        if ($data instanceof \MongoDB\BSON\UTCDateTime) {
            return ['$date' => ['$numberLong' => (string)$data]];
        }
        if ($data instanceof \MongoDB\BSON\Regex) {
            return ['$regularExpression' => ['pattern' => $data->getPattern(), 'options' => $data->getFlags()]];
        }
        if ($data instanceof \MongoDB\Model\BSONDocument) {
            return self::prepareBSON($data->getArrayCopy());
        }
        if ($data instanceof \MongoDB\Model\BSONArray) {
            return self::prepareBSON($data->getArrayCopy());
        }
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = self::prepareBSON($value);
            }
            return $result;
        }
        if (is_object($data)) {
            return self::prepareBSON((array)$data);
        }
        return $data;
    }
}
