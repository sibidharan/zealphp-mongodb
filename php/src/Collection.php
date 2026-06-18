<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use JsonSerializable;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Type;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use OpenSwoole\Coroutine\System;
use stdClass;
use Throwable;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\Exception\ErrorMapper;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;

use function array_is_list;
use function array_map;
use function array_merge;
use function base64_decode;
use function base64_encode;
use function count;
use function dechex;
use function get_debug_type;
use function get_object_vars;
use function hexdec;
use function is_array;
use function is_int;
use function is_object;
use function sprintf;
use function str_pad;
use function zealphp_mongodb_aggregate;
use function zealphp_mongodb_aggregate_all;
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
use function zealphp_mongodb_find_all;
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
use const STR_PAD_LEFT;

class Collection
{
    public function __construct(
        private int $poolId,
        private string $dbName,
        private string $colName,
        private array $options = [],
    ) {
    }

    /**
     * Map public options to the ext-level opts array. The `session` option
     * (a Session instance, as in mongodb/mongodb) becomes the internal
     * `__session` registry id the Rust ext threads through the server
     * round-trip — required for transactions.
     */
    private static function mapOptions(array $options): array|null
    {
        if (($options['session'] ?? null) instanceof Session) {
            $options['__session'] = $options['session']->getSessionId();
        }

        unset($options['session']);

        return $options ?: null;
    }

    /**
     * Reject non-integer `limit`/`skip` client-side, matching the official
     * driver which raises InvalidArgumentException before any wire I/O instead
     * of silently dropping a float-typed option (#50).
     *
     * @param array<string, mixed> $options
     */
    private static function validateQueryTypeOptions(array $options): void
    {
        foreach (['limit', 'skip'] as $option) {
            if (isset($options[$option]) && ! is_int($options[$option])) {
                throw new InvalidArgumentException(sprintf(
                    'Expected "%s" option to have type "integer" but found "%s"',
                    $option,
                    get_debug_type($options[$option]),
                ));
            }
        }
    }

    /**
     * Reject documents containing an empty-string element key at any depth,
     * matching the official driver's client-side BSON validation (#48). A
     * literal empty key is invalid per the BSON spec; the server tolerates it,
     * so without this check malformed-key bugs slip silently into the database.
     *
     * @param array<array-key, mixed> $document
     */
    private static function assertDocumentKeysNotEmpty(array $document): void
    {
        foreach ($document as $key => $value) {
            if ($key === '') {
                throw new InvalidArgumentException('invalid document for insert: Element key cannot be an empty string');
            }

            if (! is_array($value)) {
                continue;
            }

            self::assertDocumentKeysNotEmpty($value);
        }
    }

    /**
     * Run a server-touching native call and translate any structured ext error
     * into the typed MongoDB\Driver\Exception\* the official driver throws
     * (cluster C1). Non-server errors pass through unchanged.
     *
     * @param callable():mixed $native
     */
    private static function guard(callable $native): mixed
    {
        try {
            return $native();
        } catch (Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function findOne(array|object $filter = [], array $options = []): BSONDocument|Document|array|null
    {
        self::validateQueryTypeOptions($options);
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        $result = zealphp_mongodb_find_one($this->poolId, $this->dbName, $this->colName, $filter, $opts);

        return is_array($result) ? self::wrapDoc($result) : $result;
    }

    public function find(array|object $filter = [], array $options = []): Cursor|ArrayCursor
    {
        self::validateQueryTypeOptions($options);
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        if (is_array($opts) && isset($opts['__session'])) {
            // Session reads (transactions) collect eagerly — the driver-side
            // SessionCursor needs the same session borrowed per batch, so the
            // ext drains it inside one call. Bounded by the txn snapshot.
            $docs = zealphp_mongodb_find_all($this->poolId, $this->dbName, $this->colName, $filter, $opts);

            return new ArrayCursor($docs);
        }

        return Cursor::deferred($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        self::assertDocumentKeysNotEmpty((array) $document);
        $document = self::prepareBSON((array) $document);
        $opts = self::mapOptions($options);

        return new InsertOneResult(self::guard(fn () => zealphp_mongodb_insert_one($this->poolId, $this->dbName, $this->colName, $document, $opts)));
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = self::mapOptions($options);

        return new UpdateResult(self::guard(fn () => zealphp_mongodb_update_one($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts)));
    }

    public function updateMany(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = self::mapOptions($options);

        return new UpdateResult(self::guard(fn () => zealphp_mongodb_update_many($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts)));
    }

    public function deleteOne(array|object $filter, array $options = []): DeleteResult
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        return new DeleteResult(self::guard(fn () => zealphp_mongodb_delete_one($this->poolId, $this->dbName, $this->colName, $filter, $opts)));
    }

    public function deleteMany(array|object $filter, array $options = []): DeleteResult
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        return new DeleteResult(self::guard(fn () => zealphp_mongodb_delete_many($this->poolId, $this->dbName, $this->colName, $filter, $opts)));
    }

    public function replaceOne(array|object $filter, array|object $replacement, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $replacement = self::prepareBSON((array) $replacement);
        $opts = self::mapOptions($options);

        return new UpdateResult(self::guard(fn () => zealphp_mongodb_replace_one($this->poolId, $this->dbName, $this->colName, $filter, $replacement, $opts)));
    }

    public function countDocuments(array|object $filter = [], array $options = []): int
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        return zealphp_mongodb_count_documents($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function distinct(string $fieldName, array|object $filter = [], array $options = []): array
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        return zealphp_mongodb_distinct($this->poolId, $this->dbName, $this->colName, $fieldName, $filter, $opts);
    }

    public function aggregate(array $pipeline, array $options = []): Cursor|ArrayCursor
    {
        $pipeline = self::prepareBSON($pipeline);
        $opts = self::mapOptions($options);

        if (is_array($opts) && isset($opts['__session'])) {
            $docs = zealphp_mongodb_aggregate_all($this->poolId, $this->dbName, $this->colName, $pipeline, $opts);

            return new ArrayCursor($docs);
        }

        $cursorId = zealphp_mongodb_aggregate($this->poolId, $this->dbName, $this->colName, $pipeline, $opts);

        return new Cursor($cursorId);
    }

    public function findOneAndUpdate(array|object $filter, array|object $update, array $options = []): BSONDocument|Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = self::mapOptions($options);

        $result = self::guard(fn () => zealphp_mongodb_find_one_and_update($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));

        return is_array($result) ? self::wrapDoc($result) : $result;
    }

    public function findOneAndDelete(array|object $filter, array $options = []): BSONDocument|Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        $result = self::guard(fn () => zealphp_mongodb_find_one_and_delete($this->poolId, $this->dbName, $this->colName, $filter, $opts));

        return is_array($result) ? self::wrapDoc($result) : $result;
    }

    public function findOneAndReplace(array|object $filter, array|object $replacement, array $options = []): BSONDocument|Document|array|null
    {
        $filter = self::prepareBSON((array) $filter);
        $replacement = self::prepareBSON((array) $replacement);
        $opts = self::mapOptions($options);

        $result = self::guard(fn () => zealphp_mongodb_find_one_and_replace($this->poolId, $this->dbName, $this->colName, $filter, $replacement, $opts));

        return is_array($result) ? self::wrapDoc($result) : $result;
    }

    public function createIndex(array|object $key, array $options = []): string
    {
        $key = self::prepareBSON((array) $key);
        $opts = $options ?: null; // index options — no session threading

        return zealphp_mongodb_create_index($this->poolId, $this->dbName, $this->colName, $key, $opts);
    }

    public function insertMany(array $documents, array $options = []): InsertManyResult
    {
        $docs = array_map(static fn ($d) => self::prepareBSON((array) $d), $documents);
        $opts = self::mapOptions($options);

        return new InsertManyResult(self::guard(fn () => zealphp_mongodb_insert_many($this->poolId, $this->dbName, $this->colName, $docs, $opts)));
    }

    public function estimatedDocumentCount(array $options = []): int
    {
        return zealphp_mongodb_estimated_document_count($this->poolId, $this->dbName, $this->colName);
    }

    public function bulkWrite(array $operations, array $options = []): BulkWriteResult
    {
        if ($operations === []) {
            throw new InvalidArgumentException('$operations is empty');
        }

        $results = [
            'inserted_count' => 0,
            'matched_count' => 0,
            'modified_count' => 0,
            'deleted_count' => 0,
            'upserted_count' => 0,
            'inserted_ids' => [],
            'upserted_ids' => [],
            'acknowledged' => true,
        ];

        // Both inserted_ids and upserted_ids are keyed by the operation's index
        // in $operations, matching the official BulkWriteResult (#9).
        $index = -1;
        foreach ($operations as $op) {
            foreach ($op as $type => $args) {
                $index++;
                match ($type) {
                    'insertOne' => (function () use (&$results, $args, $index): void {
                        $r = $this->insertOne($args[0] ?? $args);
                        $results['inserted_count']++;
                        $results['inserted_ids'][$index] = $r->getInsertedId();
                    })(),
                    'updateOne' => (function () use (&$results, $args, $index): void {
                        $this->accumulateUpdate($results, $this->updateOne($args[0], $args[1], $args[2] ?? []), $index);
                    })(),
                    'updateMany' => (function () use (&$results, $args, $index): void {
                        $this->accumulateUpdate($results, $this->updateMany($args[0], $args[1], $args[2] ?? []), $index);
                    })(),
                    'deleteOne' => (function () use (&$results, $args): void {
                        $r = $this->deleteOne($args[0], $args[1] ?? []);
                        $results['deleted_count'] += $r->getDeletedCount();
                    })(),
                    'deleteMany' => (function () use (&$results, $args): void {
                        $r = $this->deleteMany($args[0], $args[1] ?? []);
                        $results['deleted_count'] += $r->getDeletedCount();
                    })(),
                    'replaceOne' => (function () use (&$results, $args, $index): void {
                        $this->accumulateUpdate($results, $this->replaceOne($args[0], $args[1], $args[2] ?? []), $index);
                    })(),
                    default => null,
                };
            }
        }

        return new BulkWriteResult($results);
    }

    /**
     * Fold an UpdateResult (updateOne/updateMany/replaceOne) into the running
     * bulkWrite totals, recording an upserted id at the operation index (#9).
     *
     * @param array<string, mixed> $results
     */
    private function accumulateUpdate(array &$results, UpdateResult $r, int $index): void
    {
        $results['matched_count'] += $r->getMatchedCount();
        $results['modified_count'] += $r->getModifiedCount();
        if ($r->getUpsertedCount() <= 0) {
            return;
        }

        $results['upserted_count'] += $r->getUpsertedCount();
        $results['upserted_ids'][$index] = $r->getUpsertedId();
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
        if ($indexes === []) {
            throw new InvalidArgumentException('$indexes is empty');
        }

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

    /** Watch this collection for changes (replica set required). */
    public function watch(array $pipeline = [], array $options = []): ChangeStream
    {
        return ChangeStream::open($this->poolId, $this->dbName, $this->colName, $pipeline, $options);
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

        $wrapped = new BSONDocument();
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

        if ($data instanceof Type) {
            // Official-namespace BSON value objects (real ext-mongodb classes
            // OR the polyfill) — the drop-in contract means user code passes
            // MongoDB\BSON\Binary / Timestamp / Decimal128 / MinKey / … here.
            // Found by the parity rig: these previously fell into the generic
            // object cast below and crashed on private props ("\0" keys).
            // Mapped explicitly via getters to CANONICAL extended JSON v2:
            // the polyfill's and C ext's jsonSerialize() speak the legacy v1
            // dialect ({"\$binary": "..", "\$type": ".."}) which the Rust
            // parser does not accept.
            if ($data instanceof \MongoDB\BSON\Binary) {
                return [
                    '$binary' => [
                        'base64' => base64_encode($data->getData()),
                        'subType' => str_pad(dechex($data->getType()), 2, '0', STR_PAD_LEFT),
                    ],
                ];
            }

            if ($data instanceof \MongoDB\BSON\Decimal128) {
                return ['$numberDecimal' => (string) $data];
            }

            if ($data instanceof \MongoDB\BSON\Timestamp) {
                return ['$timestamp' => ['t' => $data->getTimestamp(), 'i' => $data->getIncrement()]];
            }

            if ($data instanceof \MongoDB\BSON\Javascript) {
                $js = ['$code' => $data->getCode()];
                $scope = $data->getScope();
                if ($scope !== null) {
                    $js['$scope'] = self::prepareBSON((array) $scope);
                }

                return $js;
            }

            if ($data instanceof \MongoDB\BSON\MinKey) {
                return ['$minKey' => 1];
            }

            if ($data instanceof \MongoDB\BSON\MaxKey) {
                return ['$maxKey' => 1];
            }

            if ($data instanceof \MongoDB\BSON\Int64) {
                return (int) (string) $data;
            }

            if ($data instanceof JsonSerializable) {
                return self::prepareBSON($data->jsonSerialize());
            }
        }

        if (is_object($data)) {
            $result = new stdClass();

            // get_object_vars(): PUBLIC props only — an (array) cast mangles
            // private/protected keys with "\0Class\0" prefixes, which then
            // blow up on property assignment. Matches the official library's
            // plain-object document semantics.
            foreach (get_object_vars($data) as $key => $value) {
                $result->$key = self::prepareBSON($value);
            }

            return $result;
        }

        return $data;
    }
}
