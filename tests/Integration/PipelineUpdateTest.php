<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use MongoDB\Model\BSONArray;
use PHPUnit\Framework\TestCase;
use Throwable;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;

use function extension_loaded;
use function getenv;
use function uniqid;
use function usleep;
use function zealphp_mongodb_async_result;
use function zealphp_mongodb_close_efd;
use function zealphp_mongodb_exec_async;
use function zealphp_mongodb_update_one;

/**
 * Update pipelines (MongoDB 4.2+): an update given as a list of stages is sent
 * to the server as an array, as libmongoc / mongodb/mongodb do (#46).
 */
final class PipelineUpdateTest extends TestCase
{
    private static Client $client;
    private Collection $col;

    /** A two-stage pipeline: compute v2 from v, then drop a field. */
    private const PIPELINE = [
        ['$set' => ['v2' => ['$multiply' => ['$v', 10]]]],
        ['$unset' => 'tmp'],
    ];

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
        $this->col = $db->selectCollection('pipeupd_' . uniqid());
        $this->col->insertMany([
            ['_id' => 1, 'v' => 5, 'tmp' => 'x'],
            ['_id' => 2, 'v' => 7, 'tmp' => 'y'],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->col->drop();
        } catch (Throwable) {
        }
    }

    private function assertApplied(int $id, int $v2): void
    {
        $doc = $this->col->findOne(['_id' => $id]);
        $this->assertNotNull($doc);
        $this->assertSame($v2, $doc['v2']);
        $this->assertArrayNotHasKey('tmp', (array) $doc);
    }

    public function testUpdateOneWithPipeline(): void
    {
        $r = $this->col->updateOne(['_id' => 1], self::PIPELINE);
        $this->assertSame(1, $r->getMatchedCount());
        $this->assertSame(1, $r->getModifiedCount());
        $this->assertApplied(1, 50);
        $this->assertSame('y', $this->col->findOne(['_id' => 2])['tmp']);
    }

    public function testSingleStagePipeline(): void
    {
        $this->col->updateOne(['_id' => 1], [['$set' => ['w' => '$v']]]);
        $this->assertSame(5, $this->col->findOne(['_id' => 1])['w']);
    }

    public function testBsonArrayPipeline(): void
    {
        $this->col->updateOne(['_id' => 1], new BSONArray(self::PIPELINE));
        $this->assertApplied(1, 50);
    }

    public function testUpdateOneWithPipelineAndUpsert(): void
    {
        $r = $this->col->updateOne(['_id' => 3], [['$set' => ['v' => 1]]], ['upsert' => true]);
        $this->assertSame(0, $r->getMatchedCount());
        $this->assertSame(1, $this->col->findOne(['_id' => 3])['v']);
    }

    public function testUpdateManyWithPipeline(): void
    {
        $r = $this->col->updateMany([], self::PIPELINE);
        $this->assertSame(2, $r->getMatchedCount());
        $this->assertSame(2, $r->getModifiedCount());
        $this->assertApplied(1, 50);
        $this->assertApplied(2, 70);
    }

    public function testFindOneAndUpdateWithPipeline(): void
    {
        $doc = $this->col->findOneAndUpdate(['_id' => 2], self::PIPELINE, ['returnDocument' => 2]);
        $this->assertNotNull($doc);
        $this->assertSame(70, $doc['v2']);
        $this->assertApplied(2, 70);
    }

    public function testBulkWriteWithPipelineUpdates(): void
    {
        $r = $this->col->bulkWrite([
            ['updateOne' => [['_id' => 1], self::PIPELINE]],
            ['updateMany' => [['_id' => 2], [['$set' => ['v2' => ['$add' => ['$v', 1]]]]]]],
        ]);
        $this->assertSame(2, $r->getModifiedCount());
        $this->assertApplied(1, 50);
        $this->assertSame(8, $this->col->findOne(['_id' => 2])['v2']);
    }

    public function testOperatorDocumentStillWorks(): void
    {
        $r = $this->col->updateOne(['_id' => 1], ['$set' => ['v' => 6]]);
        $this->assertSame(1, $r->getModifiedCount());
        $this->assertSame(6, $this->col->findOne(['_id' => 1])['v']);
    }

    public function testExecAsyncUpdateOneWithPipeline(): void
    {
        $async = zealphp_mongodb_exec_async(
            self::$client->getPoolId(),
            $this->col->getDatabaseName(),
            $this->col->getCollectionName(),
            'update_one',
            ['_id' => 1],
            self::PIPELINE,
        );
        $result = null;
        for ($i = 0; $i < 500 && $result === null; $i++) {
            usleep(10_000);
            $result = zealphp_mongodb_async_result($async['task_id']);
        }

        zealphp_mongodb_close_efd($async['efd']);
        $this->assertNotNull($result);
        $this->assertApplied(1, 50);
    }

    public function testExtRejectsANonDocumentStageFromDirectCallers(): void
    {
        // $opts is a variable: ext-php-rs marks Option<&Zval> by-reference (see ExecAsyncByRefTest).
        $opts = null;
        $this->expectExceptionMessage('Update pipeline stages must be documents');
        zealphp_mongodb_update_one(
            self::$client->getPoolId(),
            $this->col->getDatabaseName(),
            $this->col->getCollectionName(),
            ['_id' => 1],
            [['$set' => ['a' => 1]], 'not-a-stage'],
            $opts,
        );
    }

    public function testPipelineUpdatesInsideATransaction(): void
    {
        $uri = getenv('MONGODB_RS_URI');
        if ($uri === false || $uri === '') {
            self::markTestSkipped('MONGODB_RS_URI not set (session path needs a replica set)');
        }

        $client = new Client($uri);
        $col = $client->selectCollection($this->col->getDatabaseName(), $this->col->getCollectionName());
        $session = $client->startSession();
        $session->startTransaction();
        $col->updateOne(['_id' => 1], self::PIPELINE, ['session' => $session]);
        $col->updateMany(['_id' => 2], self::PIPELINE, ['session' => $session]);
        $doc = $col->findOneAndUpdate(
            ['_id' => 1],
            [['$set' => ['v3' => ['$add' => ['$v2', 1]]]]],
            ['session' => $session, 'returnDocument' => 2],
        );
        $session->commitTransaction();
        $session->endSession();

        $this->assertSame(51, $doc['v3']);
        $this->assertApplied(1, 50);
        $this->assertApplied(2, 70);
    }
}
