<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use JsonSerializable;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Type;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\WriteConcern as DriverWriteConcern;
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
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;
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

class Collection
{
    public function __construct(
        private int $poolId,
        private string $dbName,
        private string $colName,
        private array $options = [],
        // Keeps the owning Client (which owns the connection pool) alive for as
        // long as this Collection is referenced, so the chained
        // `(new Client($uri))->selectCollection(...)` idiom doesn't get its pool
        // closed when the temporary Client is garbage-collected (#13). Leak-safe:
        // the pool still closes once the last derived object is gone.
        private readonly object|null $owner = null,
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

    /**
     * The official driver reports isAcknowledged() === false for an
     * unacknowledged (w:0) write; the ext always reported true (#11). Flag the
     * result unacknowledged when the effective writeConcern (per-op or
     * collection) is w:0.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function markAcknowledgement(array $result, array $options): array
    {
        $wc = $options['writeConcern'] ?? $this->options['writeConcern'] ?? null;
        $w = match (true) {
            $wc instanceof WriteConcern, $wc instanceof DriverWriteConcern => $wc->getW(),
            is_array($wc) => $wc['w'] ?? null,
            default => null,
        };

        if ($w === 0 || $w === '0') {
            $result['acknowledged'] = false;
        }

        return $result;
    }

    public function findOne(array|object $filter = [], array $options = []): array|object|null
    {
        self::validateQueryTypeOptions($options);
        $filter = self::prepareBSON((array) $filter);
        $opts = self::mapOptions($options);

        $result = zealphp_mongodb_find_one($this->poolId, $this->dbName, $this->colName, $filter, $opts);
        $wrapped = is_array($result) ? self::wrapDoc($result) : $result;

        // Apply a per-operation or collection-level typeMap to the result (#40).
        $typeMap = $options['typeMap'] ?? $this->options['typeMap'] ?? null;
        if ($wrapped !== null && $typeMap !== null) {
            return self::applyTypeMap($wrapped, $typeMap);
        }

        return $wrapped;
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
            // guard() so a server rejection (e.g. an invalid transaction
            // readConcern, #61) surfaces as a typed CommandException.
            $docs = self::guard(fn () => zealphp_mongodb_find_all($this->poolId, $this->dbName, $this->colName, $filter, $opts));

            return new ArrayCursor($docs);
        }

        return Cursor::deferred($this->poolId, $this->dbName, $this->colName, $filter, $opts);
    }

    public function insertOne(array|object $document, array $options = []): InsertOneResult
    {
        self::assertDocumentKeysNotEmpty((array) $document);
        $document = self::prepareBSON((array) $document);
        $opts = self::mapOptions($options);

        $raw = self::guard(fn () => zealphp_mongodb_insert_one($this->poolId, $this->dbName, $this->colName, $document, $opts));

        return new InsertOneResult($this->markAcknowledgement($raw, $options));
    }

    public function updateOne(array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        $filter = self::prepareBSON((array) $filter);
        $update = self::prepareBSON((array) $update);
        $opts = self::mapOptions($options);

        $raw = self::guard(fn () => zealphp_mongodb_update_one($this->poolId, $this->dbName, $this->colName, $filter, $update, $opts));

        return new UpdateResult($this->markAcknowledgement($raw, $options));
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

        $raw = self::guard(fn () => zealphp_mongodb_delete_one($this->poolId, $this->dbName, $this->colName, $filter, $opts));

        return new DeleteResult($this->markAcknowledgement($raw, $options));
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

        // Route through guard() so a server-side index conflict surfaces as a
        // typed CommandException (code 86) like the official driver, not a bare
        // \Exception — pairs with the ext no longer swallowing it (#55).
        return self::guard(fn () => zealphp_mongodb_create_index($this->poolId, $this->dbName, $this->colName, $key, $opts));
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

    public function dropIndex(string|IndexInfo $indexName, array $options = []): array
    {
        // Accept an IndexInfo (as returned by listIndexes()) as well as a name,
        // matching the official driver — passing one was a fatal TypeError (#58).
        $name = $indexName instanceof IndexInfo ? $indexName->getName() : $indexName;
        zealphp_mongodb_drop_index($this->poolId, $this->dbName, $this->colName, $name);

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

        // Route through the batched `createIndexes` command (one round-trip) so
        // the top-level $options the official driver forwards actually apply —
        // they were silently dropped when this looped over per-index createIndex
        // calls (#59). The returned name list still matches the official driver
        // exactly, via the same generateIndexName algorithm.
        $specs = [];
        $names = [];
        foreach ($indexes as $idx) {
            $idx = (array) $idx;
            $key = (array) ($idx['key'] ?? []);
            if ($key === []) {
                throw new InvalidArgumentException('"key" is required for each index');
            }

            $name = isset($idx['name']) && is_string($idx['name']) ? $idx['name'] : self::generateIndexName($key);
            $idx['name'] = $name;
            $idx['key'] = self::prepareBSON($key);
            $specs[] = $idx;
            $names[] = $name;
        }

        $cmd = ['createIndexes' => $this->colName, 'indexes' => $specs];
        foreach (['commitQuorum', 'writeConcern', 'comment', 'maxTimeMS'] as $key) {
            if (! isset($options[$key])) {
                continue;
            }

            $cmd[$key] = $options[$key];
        }

        self::guard(fn () => zealphp_mongodb_run_command($this->poolId, $this->dbName, $cmd));

        return $names;
    }

    /**
     * Generate an index name from a key spec the same way the official driver's
     * IndexInput does: each `field_<order>` pair joined by `_` (e.g. a key of
     * {x:1, y:-1} yields "x_1_y_-1"). Keeps createIndexes' returned names
     * byte-identical to mongodb/mongodb (#59).
     *
     * @param array<string, mixed> $key
     */
    private static function generateIndexName(array $key): string
    {
        $name = '';
        foreach ($key as $field => $type) {
            $name .= ($name !== '' ? '_' : '') . $field . '_' . $type;
        }

        return $name;
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

    /**
     * Re-shape a wrapped result according to a `typeMap` option (#40). The
     * default produces BSONDocument/BSONArray; a caller can ask for `array`
     * (plain arrays), `object` (stdClass), or a class name per the root /
     * document / array slots, exactly like the official driver. Value objects
     * (ObjectId, UTCDateTime, …) always pass through unchanged.
     *
     * @param array<string, string> $typeMap
     */
    private static function applyTypeMap(mixed $wrapped, array $typeMap): mixed
    {
        return self::convertContainers($wrapped, $typeMap, true);
    }

    /** @param array<string, string> $typeMap */
    private static function convertContainers(mixed $value, array $typeMap, bool $isRoot): mixed
    {
        if ($value instanceof BSONArray) {
            $items = array_map(
                static fn ($v) => self::convertContainers($v, $typeMap, false),
                $value->getArrayCopy(),
            );

            return self::toTypeMapType($typeMap['array'] ?? BSONArray::class, $items, true);
        }

        if ($value instanceof BSONDocument) {
            $fields = [];
            foreach ($value as $key => $v) {
                $fields[$key] = self::convertContainers($v, $typeMap, false);
            }

            $type = $isRoot ? ($typeMap['root'] ?? BSONDocument::class) : ($typeMap['document'] ?? BSONDocument::class);

            return self::toTypeMapType($type, $fields, false);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function toTypeMapType(string $type, array $data, bool $isArray): mixed
    {
        return match ($type) {
            'array' => $data,
            'object', 'stdClass' => (object) $data,
            BSONArray::class => new BSONArray($data),
            BSONDocument::class => $isArray ? new BSONArray($data) : new BSONDocument($data),
            default => new $type($data),
        };
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

        // No extended-JSON reconstruction here any more (#45): the ext returns a
        // real MongoDB\BSON\* object for every genuine BSON value, so a plain
        // array — even one keyed `$oid`/`$binary`/… — is a literal user
        // sub-document and must be preserved as such, not turned into a BSON type.
        $wrapped = new BSONDocument();
        foreach ($data as $key => $value) {
            $wrapped[$key] = is_array($value) ? self::wrapDoc($value) : $value;
        }

        return $wrapped;
    }

    public static function prepareBSON(mixed $data): mixed
    {
        // BSON value objects pass through UNCHANGED so the ext encodes them by
        // class — a real ObjectId/UTCDateTime/Regex becomes the BSON type, while
        // a plain array keyed `$oid`/`$date`/… stays a literal sub-document. The
        // old extended-JSON array form was indistinguishable from user data and
        // conflated the two (#45).
        if ($data instanceof ObjectId || $data instanceof UTCDateTime || $data instanceof Regex) {
            return $data;
        }

        if ($data instanceof BSONDocument) {
            return self::prepareBSON($data->getArrayCopy());
        }

        if ($data instanceof BSONArray) {
            return self::prepareBSON($data->getArrayCopy());
        }

        // The ZealPHP-namespace BSON value objects are normalized to their
        // official MongoDB\BSON\* equivalents, which the ext encodes by class
        // (#45). Note MongoDB\BSON\Timestamp's ctor is (increment, timestamp).
        if ($data instanceof Binary) {
            return new \MongoDB\BSON\Binary($data->getData(), $data->getType());
        }

        if ($data instanceof Decimal128) {
            return new \MongoDB\BSON\Decimal128((string) $data);
        }

        if ($data instanceof Timestamp) {
            return new \MongoDB\BSON\Timestamp($data->getIncrement(), $data->getTimestamp());
        }

        if ($data instanceof Javascript) {
            return new \MongoDB\BSON\Javascript($data->getCode(), $data->getScope());
        }

        if ($data instanceof MinKey) {
            return new \MongoDB\BSON\MinKey();
        }

        if ($data instanceof MaxKey) {
            return new \MongoDB\BSON\MaxKey();
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
            // Official-namespace BSON value objects (real ext-mongodb classes OR
            // the polyfill) pass through UNCHANGED — the ext encodes each by its
            // class, which is what makes a literal user array keyed `$binary`/
            // `$numberDecimal`/… unambiguous from a real BSON value (#45). They
            // must NOT be flattened to extended-JSON arrays here.
            if (
                $data instanceof \MongoDB\BSON\Binary
                || $data instanceof \MongoDB\BSON\Decimal128
                || $data instanceof \MongoDB\BSON\Timestamp
                || $data instanceof \MongoDB\BSON\Javascript
                || $data instanceof \MongoDB\BSON\MinKey
                || $data instanceof \MongoDB\BSON\MaxKey
            ) {
                return $data;
            }

            if ($data instanceof \MongoDB\BSON\Int64) {
                return (int) (string) $data;
            }

            if ($data instanceof JsonSerializable) {
                return self::prepareBSON($data->jsonSerialize());
            }
        }

        // Raw BSON containers (#70): store their actual fields/elements, not the
        // opaque {data:…} blob the generic object path produced.
        if ($data instanceof \MongoDB\BSON\Document || $data instanceof PackedArray) {
            return self::prepareBSON($data->toPHP());
        }

        // Persistable (#69): persist bsonSerialize() PLUS the __pclass marker
        // (Binary subtype 0x80 holding the class name) so the document can be
        // reconstructed to its class on read, exactly like the official driver.
        if ($data instanceof Persistable) {
            $serialized = (array) $data->bsonSerialize();
            $serialized['__pclass'] = new \MongoDB\BSON\Binary($data::class, 0x80);

            return self::prepareBSON($serialized);
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
