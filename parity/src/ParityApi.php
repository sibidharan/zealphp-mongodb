<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Parity;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

/**
 * Driver-agnostic op executor. The SAME code runs under:
 *   - Apache + mod_php + ext-mongodb (C) + mongodb/mongodb       → "c" path
 *   - ZealPHP + OpenSwoole + zealphp_mongodb (Rust) + zealphp/mongodb → "z" path
 * Driver is picked by the loaded extension; every op result goes through
 * Normalizer so the two paths can be diffed exactly.
 */
final class ParityApi
{
    private object $client;

    public string $driver;

    public function __construct(string $uri)
    {
        if (\extension_loaded('zealphp-mongodb-ext')) {
            $this->client = new \ZealPHP\MongoDB\Client($uri);
            $this->driver = 'rust';
        } elseif (\extension_loaded('mongodb')) {
            $this->client = new \MongoDB\Client($uri);
            $this->driver = 'c';
        } else {
            throw new \RuntimeException('No MongoDB driver loaded');
        }
    }

    public function handle(string $op): array
    {
        $db = $this->client->selectDatabase('parity_' . $this->driver);
        $col = $db->selectCollection('items');

        $result = match ($op) {
            'reset' => $this->reset($db, $col),
            'crud' => $this->crud($col),
            'query' => $this->query($col),
            'aggregate' => $this->aggregate($col),
            'types' => $this->types($db->selectCollection('types')),
            'indexes' => $this->indexes($db->selectCollection('idx')),
            'bulk' => $this->bulk($db->selectCollection('bulk')),
            'txn_commit' => $this->txn($db->selectCollection('txn'), commit: true),
            'txn_abort' => $this->txn($db->selectCollection('txn'), commit: false),
            'change_stream' => $this->changeStream($db->selectCollection('cs')),
            'gridfs' => $this->gridfs($db),
            'errors' => $this->errors($db),
            'options' => $this->options($db),
            'int_types' => $this->intTypes($db),
            'write_results' => $this->writeResults($db),
            'insert_ids' => $this->insertIds($db),
            'bulk_ids' => $this->bulkIds($db),
            'find_opts' => $this->findOpts($db),
            'index_spec' => $this->indexSpec($db),
            'list_filter' => $this->listFilter($db),
            'index_flags' => $this->indexFlags($db),
            default => throw new \InvalidArgumentException("unknown op: $op"),
        };

        return ['driver' => $this->driver, 'op' => $op, 'result' => Normalizer::normalize($result)];
    }

    /** Deterministic dataset — fixed ids/values, no clock, no randomness. */
    private function reset(object $db, object $col): array
    {
        $db->drop();
        $docs = [];
        foreach (range(1, 20) as $i) {
            $docs[] = [
                '_id' => new ObjectId(\str_pad(\dechex($i), 24, '0', STR_PAD_LEFT)),
                'n' => $i,
                'grp' => $i % 4,
                'name' => "item-$i",
                'tags' => $i % 2 ? ['odd', "t$i"] : ['even'],
                'nested' => ['depth' => ['value' => $i * 10]],
            ];
        }

        $col->insertMany($docs);

        return ['seeded' => $col->countDocuments([])];
    }

    private function crud(object $col): array
    {
        $out = [];
        $ins = $col->insertOne(['_id' => new ObjectId('aaaaaaaaaaaaaaaaaaaaaaaa'), 'k' => 'crud', 'v' => 1]);
        $out['inserted_id'] = $ins->getInsertedId();

        $out['find_one'] = $col->findOne(['k' => 'crud']);

        $up = $col->updateOne(['k' => 'crud'], ['$set' => ['v' => 2], '$inc' => ['hits' => 1]]);
        $out['update'] = ['matched' => $up->getMatchedCount(), 'modified' => $up->getModifiedCount()];

        $ups = $col->updateOne(['k' => 'crud-upsert'], ['$set' => ['v' => 9]], ['upsert' => true]);
        $out['upsert_matched'] = $ups->getMatchedCount();

        $out['find_one_and_update'] = $col->findOneAndUpdate(
            ['k' => 'crud'],
            ['$set' => ['v' => 3]],
            ['returnDocument' => 2], // After — both libs accept the int form
        );

        $rep = $col->replaceOne(['k' => 'crud'], ['k' => 'crud', 'replaced' => true]);
        $out['replace_modified'] = $rep->getModifiedCount();

        $out['find_one_and_delete'] = $col->findOneAndDelete(['k' => 'crud']);
        $del = $col->deleteMany(['k' => ['$in' => ['crud', 'crud-upsert']]]);
        $out['deleted'] = $del->getDeletedCount();

        return $out;
    }

    private function query(object $col): array
    {
        return [
            'count' => $col->countDocuments(['grp' => 1]),
            'distinct_grp' => $col->distinct('grp'),
            'sorted_page' => \iterator_to_array($col->find(
                ['n' => ['$gte' => 5]],
                ['sort' => ['n' => -1], 'limit' => 4, 'skip' => 1, 'projection' => ['_id' => 0, 'n' => 1, 'name' => 1]],
            ), false),
            'nested_match' => $col->findOne(['nested.depth.value' => 100], ['projection' => ['_id' => 0]]),
            'in_or' => $col->countDocuments(['$or' => [['grp' => 0], ['tags' => 'odd']]]),
            'regex' => $col->countDocuments(['name' => new Regex('^item-1[0-9]$')]),
        ];
    }

    private function aggregate(object $col): array
    {
        return [
            'group' => \iterator_to_array($col->aggregate([
                ['$match' => ['n' => ['$lte' => 16]]],
                ['$group' => ['_id' => '$grp', 'total' => ['$sum' => '$n'], 'cnt' => ['$sum' => 1]]],
                ['$sort' => ['_id' => 1]],
            ]), false),
            'unwind' => \iterator_to_array($col->aggregate([
                ['$match' => ['n' => ['$in' => [1, 2]]]],
                ['$unwind' => '$tags'],
                ['$project' => ['_id' => 0, 'n' => 1, 'tags' => 1]],
                ['$sort' => ['n' => 1, 'tags' => 1]],
            ]), false),
        ];
    }

    private function types(object $col): array
    {
        $col->drop();
        $doc = [
            '_id' => 'types-1',
            'oid' => new ObjectId('bbbbbbbbbbbbbbbbbbbbbbbb'),
            'date' => new UTCDateTime(1718000000000),
            'dec' => new Decimal128('1234.5678'),
            'bin' => new Binary("\x01\x02\xff", Binary::TYPE_GENERIC),
            'regex' => new Regex('^ab+c', 'i'),
            'min' => new MinKey(),
            'max' => new MaxKey(),
            'null' => null,
            'bool' => true,
            'int' => 42,
            'float' => 3.5,
            'str' => 'näïve ☃',
            'arr' => [1, 'two', [3.5, ['deep' => true]]],
        ];
        $col->insertOne($doc);

        return ['roundtrip' => $col->findOne(['_id' => 'types-1'])];
    }

    private function indexes(object $col): array
    {
        $col->drop();
        $col->insertOne(['a' => 1, 'b' => 2]);
        $name = $col->createIndex(['a' => 1, 'b' => -1], ['unique' => true, 'name' => 'ab_unique']);
        $list = [];
        foreach ($col->listIndexes() as $idx) {
            $list[] = ['name' => $idx['name'] ?? (string) $idx->getName(), 'key' => $idx['key'] ?? $idx->getKey()];
        }

        \usort($list, static fn ($x, $y) => \strcmp($x['name'], $y['name']));
        $col->dropIndex('ab_unique');

        return ['created' => $name, 'listed' => $list];
    }

    private function bulk(object $col): array
    {
        $col->drop();
        $r = $col->bulkWrite([
            ['insertOne' => [['k' => 1]]],
            ['insertOne' => [['k' => 2]]],
            ['updateOne' => [['k' => 1], ['$set' => ['u' => true]]]],
            ['deleteOne' => [['k' => 2]]],
        ]);

        return [
            'inserted' => $r->getInsertedCount(),
            'modified' => $r->getModifiedCount(),
            'deleted' => $r->getDeletedCount(),
            'final_count' => $col->countDocuments([]),
        ];
    }

    private function txn(object $col, bool $commit): array
    {
        $col->drop();
        $col->insertOne(['k' => 'base']); // materialize for txn on RS

        $session = $this->client->startSession();
        $session->startTransaction();
        $col->insertOne(['k' => 'in-txn-1'], ['session' => $session]);
        $col->insertOne(['k' => 'in-txn-2'], ['session' => $session]);
        $inTxn = $col->countDocuments([], ['session' => $session]);
        $commit ? $session->commitTransaction() : $session->abortTransaction();
        $session->endSession();

        return ['visible_inside' => $inTxn, 'visible_after' => $col->countDocuments([])];
    }

    private function changeStream(object $col): array
    {
        $col->drop();
        $col->insertOne(['warmup' => true]);

        $stream = $col->watch(
            [['$match' => ['operationType' => ['$in' => ['insert', 'delete']]]]],
            ['maxAwaitTimeMS' => 300, 'fullDocument' => 'updateLookup'],
        );
        $stream->rewind();

        $col->insertOne(['_id' => 'ev-1', 'payload' => 'first']);
        $col->updateOne(['_id' => 'ev-1'], ['$set' => ['payload' => 'filtered-out']]);
        $col->deleteOne(['_id' => 'ev-1']);

        $events = [];
        $deadline = \microtime(true) + 8.0;
        while (\count($events) < 2 && \microtime(true) < $deadline) {
            $stream->next();
            if (! $stream->valid()) {
                continue;
            }

            $ev = $stream->current();
            $events[] = [
                'type' => $ev['operationType'] ?? null,
                'doc' => isset($ev['fullDocument']) ? Normalizer::normalize($ev['fullDocument']) : null,
            ];
        }

        return ['events' => $events, 'resume_token_present' => $stream->getResumeToken() !== null];
    }

    private function gridfs(object $db): array
    {
        $bucket = $db->selectGridFSBucket(['bucketName' => 'pfs']);
        try {
            $bucket->drop();
        } catch (\Throwable) {
            // first run — bucket collections don't exist yet
        }

        $payload = \str_repeat('zealphp-parity-', 20000); // 300 KB → 2 chunks
        $s = \fopen('php://temp', 'r+b');
        \fwrite($s, $payload);
        \rewind($s);
        $id = $bucket->uploadFromStream('parity.bin', $s, ['metadata' => ['tag' => 'p1']]);

        $s2 = \fopen('php://temp', 'r+b');
        \fwrite($s2, 'v2-content');
        \rewind($s2);
        $bucket->uploadFromStream('parity.bin', $s2);

        $back = \stream_get_contents($bucket->openDownloadStream($id));
        $latest = \stream_get_contents($bucket->openDownloadStreamByName('parity.bin'));
        $first = \stream_get_contents($bucket->openDownloadStreamByName('parity.bin', ['revision' => 0]));

        $fileDoc = $bucket->findOne(['_id' => $id]);
        $chunkCount = $db->selectCollection('pfs.chunks')->countDocuments(['files_id' => $id]);

        $bucket->delete($id);
        $afterDelete = $db->selectCollection('pfs.files')->countDocuments([]);

        return [
            'sha_by_id' => \hash('sha256', $back),
            'len_by_id' => \strlen($back),
            'latest_is_v2' => $latest === 'v2-content',
            'revision0_sha' => \hash('sha256', $first),
            'file_len' => $fileDoc['length'] ?? null,
            'metadata_tag' => $fileDoc['metadata']['tag'] ?? null,
            'chunks' => $chunkCount,
            'files_after_delete' => $afterDelete,
        ];
    }

    /**
     * Typed-exception parity (cluster C1): a duplicate-key insert and a bad
     * command must throw the SAME exception class with the SAME server code on
     * both drivers — not a bare \Exception with code 0.
     */
    private function errors(object $db): array
    {
        $col = $db->selectCollection('errs');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run — collection doesn't exist yet
        }

        $col->insertOne(['_id' => 1, 'v' => 'seed']);

        return [
            'dup_key' => $this->captureError(static fn () => $col->insertOne(['_id' => 1, 'v' => 'dup'])),
            'bad_command' => $this->captureError(static fn () => $db->command(['thisIsNotARealCommand' => 1])),
        ];
    }

    /** @param callable():mixed $fn */
    private function captureError(callable $fn): array
    {
        try {
            $fn();

            return ['threw' => false];
        } catch (\Throwable $e) {
            $info = [
                'threw' => true,
                'class' => \get_class($e),
                'code' => $e->getCode(),
                'is_driver_exception' => $e instanceof \MongoDB\Driver\Exception\Exception,
                'is_bulk_write' => $e instanceof \MongoDB\Driver\Exception\BulkWriteException,
                'is_command' => $e instanceof \MongoDB\Driver\Exception\CommandException,
            ];

            if ($e instanceof \MongoDB\Driver\Exception\BulkWriteException) {
                $writeErrors = $e->getWriteResult()->getWriteErrors();
                $info['first_write_error_code'] = isset($writeErrors[0]) ? $writeErrors[0]->getCode() : null;
            }

            return $info;
        }
    }

    /**
     * Operation-option parity (cluster C2): options the PHP layer forwards must
     * actually reach the server. `sort` on findOneAndUpdate must pick the
     * highest-`a` doc (not natural-order first); `arrayFilters` must resolve
     * the positional `$[e]` identifier (otherwise the server rejects the op).
     */
    private function options(object $db): array
    {
        $col = $db->selectCollection('opts');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $col->insertMany([
            ['_id' => 5, 'a' => 10],
            ['_id' => 2, 'a' => 50],
            ['_id' => 9, 'a' => 30],
        ]);

        // sort {a:-1} => the _id=2 doc (highest a) is the one selected/updated.
        $sorted = $col->findOneAndUpdate([], ['$set' => ['hit' => 1]], ['sort' => ['a' => -1], 'returnDocument' => 2]);

        // arrayFilters: set every grade >= 85 to 100 => [80, 100, 100].
        $col->insertOne(['_id' => 100, 'grades' => [80, 85, 90]]);
        $col->updateOne(
            ['_id' => 100],
            ['$set' => ['grades.$[e]' => 100]],
            ['arrayFilters' => [['e' => ['$gte' => 85]]]],
        );
        $afDoc = $col->findOne(['_id' => 100]);

        return [
            'sort_selected_id' => $sorted['_id'] ?? null,
            'sort_selected_a' => $sorted['a'] ?? null,
            'sort_selected_hit' => $sorted['hit'] ?? null,
            'array_filters_grades' => $afDoc['grades'] ?? null,
        ];
    }

    /**
     * Integer width parity (cluster C3 / #44): a PHP int that fits in 32 bits
     * must be stored as BSON int32, a larger one as int64 — verified via $type
     * queries (read-back alone can't tell, since both map to a PHP int).
     */
    private function intTypes(object $db): array
    {
        $col = $db->selectCollection('inttypes');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $col->insertOne(['_id' => 1, 'small' => 5, 'big' => 5000000000]);

        return [
            'small_is_int' => $col->countDocuments(['small' => ['$type' => 'int']]),
            'small_is_long' => $col->countDocuments(['small' => ['$type' => 'long']]),
            'big_is_int' => $col->countDocuments(['big' => ['$type' => 'int']]),
            'big_is_long' => $col->countDocuments(['big' => ['$type' => 'long']]),
        ];
    }

    /**
     * Write-result parity (cluster C4 / #8): an upsert must report
     * getUpsertedCount()===1 and getUpsertedId()===<_id>; a matching update
     * reports upsertedCount 0 + matched/modified 1. An explicit string _id
     * keeps the upserted id deterministic across both drivers.
     */
    private function writeResults(object $db): array
    {
        $col = $db->selectCollection('wres');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $up1 = $col->updateOne(['_id' => 'wr-upsert'], ['$set' => ['v' => 1]], ['upsert' => true]);
        $up2 = $col->updateOne(['_id' => 'wr-upsert'], ['$set' => ['v' => 2]], ['upsert' => true]);

        return [
            'up1_upserted_count' => $up1->getUpsertedCount(),
            'up1_upserted_id' => $up1->getUpsertedId(),
            'up1_matched' => $up1->getMatchedCount(),
            'up1_modified' => $up1->getModifiedCount(),
            'up2_upserted_count' => $up2->getUpsertedCount(),
            'up2_upserted_id' => $up2->getUpsertedId(),
            'up2_matched' => $up2->getMatchedCount(),
            'up2_modified' => $up2->getModifiedCount(),
        ];
    }

    /**
     * insertMany id-mapping parity (cluster C4 / #10): getInsertedIds() must be
     * keyed by INPUT document index, not shuffled by the ext's HashMap order.
     * Explicit string _ids keep the values comparable across drivers.
     */
    private function insertIds(object $db): array
    {
        $col = $db->selectCollection('insids');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $res = $col->insertMany([
            ['_id' => 'doc-a', 'n' => 0],
            ['_id' => 'doc-b', 'n' => 1],
            ['_id' => 'doc-c', 'n' => 2],
            ['_id' => 'doc-d', 'n' => 3],
            ['_id' => 'doc-e', 'n' => 4],
        ]);
        $ids = $res->getInsertedIds();

        return [
            'count' => $res->getInsertedCount(),
            'id_0' => $ids[0] ?? null,
            'id_1' => $ids[1] ?? null,
            'id_2' => $ids[2] ?? null,
            'id_3' => $ids[3] ?? null,
            'id_4' => $ids[4] ?? null,
        ];
    }

    /**
     * bulkWrite result parity (cluster C4 / #9): inserted/upserted counts and
     * ids (keyed by operation index) must be reported, and getInsertedIds()
     * must exist (it was a fatal undefined-method Error).
     */
    private function bulkIds(object $db): array
    {
        $col = $db->selectCollection('bwres');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $col->insertOne(['_id' => 'existing', 'v' => 0]);
        $res = $col->bulkWrite([
            ['insertOne' => [['_id' => 'ins-1', 'v' => 1]]],
            ['updateOne' => [['_id' => 'up-1'], ['$set' => ['v' => 2]], ['upsert' => true]]],
            ['updateOne' => [['_id' => 'existing'], ['$set' => ['v' => 9]]]],
            ['deleteOne' => [['_id' => 'existing']]],
        ]);

        return [
            'inserted_count' => $res->getInsertedCount(),
            'upserted_count' => $res->getUpsertedCount(),
            'matched_count' => $res->getMatchedCount(),
            'modified_count' => $res->getModifiedCount(),
            'deleted_count' => $res->getDeletedCount(),
            'inserted_ids' => $res->getInsertedIds(),
            'upserted_ids' => $res->getUpsertedIds(),
        ];
    }

    /**
     * find() option parity (cluster C2 / #47): returnKey returns only the index
     * key (no _id / non-indexed fields); showRecordId injects $recordId. Both
     * were silently dropped. ($recordId VALUE is storage-internal and differs
     * per deployment, so only its presence is compared.)
     */
    private function findOpts(object $db): array
    {
        $col = $db->selectCollection('findopts');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $col->insertMany([['a' => 1, 'b' => 10], ['a' => 2, 'b' => 20]]);
        $col->createIndex(['a' => 1]);

        $rk = $col->find(['a' => ['$gte' => 1]], ['returnKey' => true, 'sort' => ['a' => 1]])->toArray();
        $sr = $col->findOne(['a' => 1], ['showRecordId' => true]);

        return [
            'return_key_first_keys' => isset($rk[0]) ? \array_keys((array) $rk[0]) : null,
            'return_key_count' => \count($rk),
            'show_record_id_present' => isset($sr['$recordId']),
        ];
    }

    /**
     * Index-spec parity (cluster C2 / #16, #17): createIndex must forward
     * partialFilterExpression / hidden, and listIndexes must surface the full
     * server spec (expireAfterSeconds, partialFilterExpression, hidden) rather
     * than a stripped key/name/unique/sparse projection.
     */
    private function indexSpec(object $db): array
    {
        $col = $db->selectCollection('idxspec');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $col->insertOne(['_id' => 1, 'ts' => 0, 'pf' => 0, 'h' => 0]);
        $col->createIndex(['ts' => 1], ['name' => 'ttl_idx', 'expireAfterSeconds' => 3600]);
        $col->createIndex(['pf' => 1], ['name' => 'partial_idx', 'partialFilterExpression' => ['pf' => ['$gt' => 5]]]);
        $col->createIndex(['h' => 1], ['name' => 'hidden_idx', 'hidden' => true]);

        $byName = [];
        foreach ($col->listIndexes() as $ix) {
            $byName[$ix['name']] = $ix;
        }

        return [
            'ttl_expire' => $byName['ttl_idx']['expireAfterSeconds'] ?? 'MISSING',
            'partial_present' => isset($byName['partial_idx']['partialFilterExpression']) ? 'present' : 'MISSING',
            'hidden_flag' => $byName['hidden_idx']['hidden'] ?? 'MISSING',
        ];
    }

    /**
     * listCollectionNames filter parity (cluster admin / #34): the `filter`
     * option must actually restrict the returned names instead of being
     * dropped (every collection came back regardless).
     */
    private function listFilter(object $db): array
    {
        foreach (['alpha', 'beta', 'gamma'] as $c) {
            $db->selectCollection($c)->insertOne(['_id' => 1]);
        }

        // Official listCollectionNames returns an Iterator, zeal an array;
        // normalize both so the test targets the FILTER behaviour, not the
        // return-container type.
        $toArr = static fn ($v): array => \is_array($v) ? $v : \iterator_to_array($v);
        $all = $toArr($db->listCollectionNames());
        $filtered = $toArr($db->listCollectionNames(['filter' => ['name' => 'beta']]));
        \sort($filtered);

        return [
            'all_contains' => \array_values(\array_intersect(['alpha', 'beta', 'gamma'], $all)),
            'filtered' => \array_values($filtered),
        ];
    }

    /**
     * IndexInfo getters (#18) and empty-bulkWrite validation (#12): is2dSphere()
     * / isText() must classify the index type, and bulkWrite([]) must throw
     * InvalidArgumentException instead of silently returning a zero result.
     */
    private function indexFlags(object $db): array
    {
        $col = $db->selectCollection('idxflags');
        try {
            $col->drop();
        } catch (\Throwable) {
            // first run
        }

        $col->insertOne(['_id' => 1, 'loc' => ['type' => 'Point', 'coordinates' => [0, 0]], 'txt' => 'hello']);
        $col->createIndex(['loc' => '2dsphere'], ['name' => 'geo']);
        $col->createIndex(['txt' => 'text'], ['name' => 'txt_idx']);

        $flags = [];
        foreach ($col->listIndexes() as $ix) {
            $flags[$ix->getName()] = ['is2dSphere' => $ix->is2dSphere(), 'isText' => $ix->isText()];
        }

        $emptyBulk = 'NO_THROW';
        try {
            $col->bulkWrite([]);
        } catch (\Throwable $e) {
            $emptyBulk = $e instanceof \MongoDB\Exception\InvalidArgumentException
                ? 'InvalidArgumentException'
                : \get_class($e);
        }

        return [
            'geo' => $flags['geo'] ?? null,
            'text' => $flags['txt_idx'] ?? null,
            'empty_bulk' => $emptyBulk,
        ];
    }
}
