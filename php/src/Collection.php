<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use OpenSwoole\Coroutine\System;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\Timestamp;

use function array_is_list;
use function array_map;
use function array_merge;
use function base64_decode;
use function count;
use function hexdec;
use function is_array;
use function is_object;
use function zealphp_mongodb_aggregate;
use function zealphp_mongodb_batch_result;
use function zealphp_mongodb_close_efd;
use function zealphp_mongodb_count_documents;
use function zealphp_mongodb_create_index;
use function zealphp_mongodb_delete_many;
use function zealphp_mongodb_delete_one;
use function zealphp_mongodb_distinct;
use function zealphp_mongodb_drop_collection;
use function zealphp_mongodb_drop_index;
use function zealphp_mongodb_drop_indexes;
use function zealphp_mongodb_estimated_document_count;
use function zealphp_mongodb_find;
use function zealphp_mongodb_find_one;
use function zealphp_mongodb_find_one_and_delete;
use function zealphp_mongodb_find_one_and_replace;
use function zealphp_mongodb_find_one_and_update;
use function zealphp_mongodb_insert_many;
use function zealphp_mongodb_insert_one;
use function zealphp_mongodb_list_indexes;
use function zealphp_mongodb_replace_one;
use function zealphp_mongodb_run_command;
use function zealphp_mongodb_update_many;
use function zealphp_mongodb_update_one;

use const OPENSWOOLE_EVENT_READ;

class Collection
{
    public function __construct(
        private int $poolId,
        private string $dbName,
        private string $colName,
        private array $options = [],
    ) {
    }

    public function findOne(array|object $filter = [], array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = $options ?: null;

        return zealphp_mongodb_find_one($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function find(array|object $filter = [], array $options = []): Cursor
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = $options ?: null;

        return Cursor::deferred($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        $document = self::prepareBSON((array) $document);
        $opts = null;

        return new InsertOneResult(zealphp_mongodb_insert_one($this->poolId, $this->dbName, $this->colName, $document, $opts));
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = $options ?: null;

        return new UpdateResult(zealphp_mongodb_update_one($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));
    }

    public function updateMany(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = $options ?: null;

        return new UpdateResult(zealphp_mongodb_update_many($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));
    }

    public function deleteOne(array|object $filter, array $options = []): DeleteResult
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = null;

        return new DeleteResult(zealphp_mongodb_delete_one($this->poolId, $this->dbName, $this->colName, $filter, $opts));
    }

    public function deleteMany(array|object $filter, array $options = []): DeleteResult
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = null;

        return new DeleteResult(zealphp_mongodb_delete_many($this->poolId, $this->dbName, $this->colName, $filter, $opts));
    }

    public function replaceOne(array|object $filter, array|object $replacement, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $replacement = self::prepareBSON((array) $replacement);
        $opts = $options ?: null;

        return new UpdateResult(zealphp_mongodb_replace_one($this->poolId, $this->dbName, $this->colName, $filter, $replacement, $opts));
    }

    public function countDocuments(array|object $filter = [], array $options = []): int
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = null;

        return zealphp_mongodb_count_documents($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function distinct(string $fieldName, array|object $filter = [], array $options = []): array
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = null;

        return zealphp_mongodb_distinct($this->poolId, $this->dbName, $this->colName, $fieldName, $filter, $opts);
    }

    public function aggregate(array $pipeline, array $options = []): Cursor
    {
        $pipeline = self::prepareBSON($pipeline);
        $opts = $options ?: null;
        $cursorId = zealphp_mongodb_aggregate($this->poolId, $this->dbName, $this->colName, $pipeline, $opts);

        return new Cursor($cursorId);
    }

    public function findOneAndUpdate(array|object $filter, array|object $update, array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = $options ?: null;

        return zealphp_mongodb_find_one_and_update($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts);
    }

    public function findOneAndDelete(array|object $filter, array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = null;

        return zealphp_mongodb_find_one_and_delete($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function findOneAndReplace(array|object $filter, array|object $replacement, array $options = []): Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $replacement = self::prepareBSON((array) $replacement);
        $opts = $options ?: null;

        return zealphp_mongodb_find_one_and_replace($this->poolId, $this->dbName, $this->colName, $filter, $replacement, $opts);
    }

    public function createIndex(array|object $key, array $options = []): string
    {
        $key = self::prepareBSON((array) $key);
        $opts = $options ?: null;

        return zealphp_mongodb_create_index($this->poolId, $this->dbName, $this->colName, $key, $opts);
    }

    public function insertMany(array $documents, array $options = []): InsertManyResult
    {
        $docs = array_map(static fn ($d) => self::prepareBSON((array) $d), $documents);
        $opts = $options ?: null;

        return new InsertManyResult(zealphp_mongodb_insert_many($this->poolId, $this->dbName, $this->colName, $docs, $opts));
    }

    public function estimatedDocumentCount(array $options = []): int
    {
        return zealphp_mongodb_estimated_document_count($this->poolId, $this->dbName, $this->colName);
    }

    public function bulkWrite(array $operations, array $options = []): BulkWriteResult
    {
        $results = ['inserted_count' => 0, 'matched_count' => 0, 'modified_count' => 0, 'deleted_count' => 0, 'upserted_count' => 0, 'acknowledged' => true];
        foreach ($operations as $op) {
            foreach ($op as $type => $args) {
                match ($type) {
                    'insertOne' => (function () use (&$results, $args) {
                        $this->insertOne($args[0] ?? $args);
                        $results['inserted_count']++;
                    })(),
                    'updateOne' => (function () use (&$results, $args) {
                        $r = $this->updateOne($args[0], $args[1], $args[2] ?? []);
                        $results['matched_count'] += $r->getMatchedCount();
                        $results['modified_count'] += $r->getModifiedCount();
                    })(),
                    'updateMany' => (function () use (&$results, $args) {
                        $r = $this->updateMany($args[0], $args[1], $args[2] ?? []);
                        $results['matched_count'] += $r->getMatchedCount();
                        $results['modified_count'] += $r->getModifiedCount();
                    })(),
                    'deleteOne' => (function () use (&$results, $args) {
                        $r = $this->deleteOne($args[0], $args[1] ?? []);
                        $results['deleted_count'] += $r->getDeletedCount();
                    })(),
                    'deleteMany' => (function () use (&$results, $args) {
                        $r = $this->deleteMany($args[0], $args[1] ?? []);
                        $results['deleted_count'] += $r->getDeletedCount();
                    })(),
                    'replaceOne' => (function () use (&$results, $args) {
                        $r = $this->replaceOne($args[0], $args[1], $args[2] ?? []);
                        $results['matched_count'] += $r->getMatchedCount();
                        $results['modified_count'] += $r->getModifiedCount();
                    })(),
                    default => null,
                };
            }
        }

        return new BulkWriteResult($results);
    }

    public function drop(array $options = []): array
    {
        zealphp_mongodb_drop_collection($this->poolId, $this->dbName, $this->colName);

        return ['ok' => 1];
    }

    public function rename(string $toCollectionName, string|null $toDatabaseName = null, array $options = []): array
    {
        $cmd = ['renameCollection' => $this->dbName . '.' . $this->colName, 'to' => ($toDatabaseName ?? $this->dbName) . '.' . $toCollectionName];
        zealphp_mongodb_run_command($this->poolId, 'admin', $cmd);
        $this->colName = $toCollectionName;
        if ($toDatabaseName) {
            $this->dbName = $toDatabaseName;
        }

        return ['ok' => 1];
    }

    public function listIndexes(array $options = []): array
    {
        $raw = zealphp_mongodb_list_indexes($this->poolId, $this->dbName, $this->colName);

        return array_map(static fn ($idx) => new IndexInfo(is_array($idx) ? $idx : (array) $idx), $raw);
    }

    public function dropIndex(string $indexName, array $options = []): array
    {
        zealphp_mongodb_drop_index($this->poolId, $this->dbName, $this->colName, $indexName);

        return ['ok' => 1];
    }

    public function dropIndexes(array $options = []): array
    {
        zealphp_mongodb_drop_indexes($this->poolId, $this->dbName, $this->colName);

        return ['ok' => 1];
    }

    public function createIndexes(array $indexes, array $options = []): array
    {
        $names = [];
        foreach ($indexes as $idx) {
            $key = $idx['key'] ?? [];
            $idxOpts = $idx;
            unset($idxOpts['key']);
            $names[] = $this->createIndex($key, $idxOpts);
        }

        return $names;
    }

    public function withOptions(array $options = []): self
    {
        $new = clone $this;
        $new->options = array_merge($this->options, $options);

        return $new;
    }

    public function count(array|object $filter = [], array $options = []): int
    {
        return $this->countDocuments($filter, $options);
    }

    public function getCollectionName(): string
    {
        return $this->colName;
    }

    public function getDatabaseName(): string
    {
        return $this->dbName;
    }

    public function getNamespace(): string
    {
        return $this->dbName . '.' . $this->colName;
    }

    private ReadConcern|null $readConcern = null;
    private WriteConcern|null $writeConcern = null;
    private ReadPreference|null $readPreference = null;

    public function getReadConcern(): ReadConcern
    {
        return $this->readConcern ?? new ReadConcern();
    }

    public function getWriteConcern(): WriteConcern
    {
        return $this->writeConcern ?? new WriteConcern(1);
    }

    public function getReadPreference(): ReadPreference
    {
        return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY);
    }

    public function getTypeMap(): array
    {
        return ['root' => BSONDocument::class, 'document' => BSONDocument::class, 'array' => BSONArray::class];
    }

    /** @return array<string, mixed> */
    public static function awaitBatch(array $async): array
    {
        $efd = $async['efd'];
        $taskId = $async['task_id'];

        System::waitEvent($efd, OPENSWOOLE_EVENT_READ, 30);
        /** @var array<string, mixed> $result */
        $result = zealphp_mongodb_batch_result($taskId);
        zealphp_mongodb_close_efd($efd);

        return $result;
    }

    public static function wrapDoc(mixed $data): mixed
    {
        if ($data === null) {
            return null;
        }

        if (! is_array($data)) {
            return $data;
        }

        if (array_is_list($data)) {
            $wrapped = array_map([self::class, 'wrapDoc'], $data);

            return new BSONArray($wrapped);
        }

        $c = count($data);

        if ($c === 1 && isset($data['$oid'])) {
            return new ObjectId($data['$oid']);
        }

        if ($c === 1 && isset($data['$date'])) {
            if (isset($data['$date']['$numberLong'])) {
                return new UTCDateTime((int) $data['$date']['$numberLong']);
            }

            return new UTCDateTime((int) $data['$date']);
        }

        if ($c === 1 && isset($data['$numberDecimal'])) {
            return new Decimal128($data['$numberDecimal']);
        }

        if ($c === 1 && isset($data['$binary'])) {
            return new Binary(
                base64_decode($data['$binary']['base64'] ?? ''),
                (int) hexdec($data['$binary']['subType'] ?? '00'),
            );
        }

        if ($c === 1 && isset($data['$regularExpression'])) {
            return new Regex(
                $data['$regularExpression']['pattern'] ?? '',
                $data['$regularExpression']['options'] ?? '',
            );
        }

        if ($c === 1 && isset($data['$timestamp'])) {
            return new Timestamp(
                $data['$timestamp']['i'] ?? 0,
                $data['$timestamp']['t'] ?? 0,
            );
        }

        if (isset($data['$code']) && ($c === 1 || ($c === 2 && isset($data['$scope'])))) {
            return new Javascript($data['$code'], $data['$scope'] ?? null);
        }

        if ($c === 1 && isset($data['$minKey'])) {
            return new MinKey();
        }

        if ($c === 1 && isset($data['$maxKey'])) {
            return new MaxKey();
        }

        $wrapped = new Document();
        foreach ($data as $key => $value) {
            $wrapped[$key] = is_array($value) ? self::wrapDoc($value) : $value;
        }

        return $wrapped;
    }

    public static function prepareBSON(mixed $data): mixed
    {
        if ($data instanceof ObjectId) {
            return ['$oid' => $data->__toString()];
        }

        if ($data instanceof UTCDateTime) {
            return ['$date' => ['$numberLong' => $data->__toString()]];
        }

        if ($data instanceof Regex) {
            return ['$regularExpression' => ['pattern' => $data->getPattern(), 'options' => $data->getFlags()]];
        }

        if ($data instanceof BSONDocument) {
            return self::prepareBSON($data->getArrayCopy());
        }

        if ($data instanceof BSONArray) {
            return self::prepareBSON($data->getArrayCopy());
        }

        if ($data instanceof Binary) {
            return $data->jsonSerialize();
        }

        if ($data instanceof Decimal128) {
            return $data->jsonSerialize();
        }

        if ($data instanceof Timestamp) {
            return $data->jsonSerialize();
        }

        if ($data instanceof Javascript) {
            return $data->jsonSerialize();
        }

        if ($data instanceof MinKey) {
            return $data->jsonSerialize();
        }

        if ($data instanceof MaxKey) {
            return $data->jsonSerialize();
        }

        if ($data instanceof Int64) {
            return (int) (string) $data;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = self::prepareBSON($value);
            }

            return $result;
        }

        if (is_object($data)) {
            return self::prepareBSON((array) $data);
        }

        return $data;
    }
}
