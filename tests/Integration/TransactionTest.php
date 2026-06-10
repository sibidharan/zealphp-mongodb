<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use ZealPHP\MongoDB\ArrayCursor;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\Session;

use function extension_loaded;
use function function_exists;
use function getenv;
use function iterator_to_array;
use function uniqid;

/**
 * Real server-backed transactions (C-driver parity). Requires a REPLICA SET
 * (MongoDB refuses transactions on standalones) — point MONGODB_RS_URI at one,
 * e.g. mongodb://host:27036/?replicaSet=rs0. Skips cleanly otherwise.
 */
class TransactionTest extends TestCase
{
    private static Client $client;
    private static string $db = 'zealphp_txn_test';
    private Collection $col;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        if (! function_exists('zealphp_mongodb_session_start')) {
            self::markTestSkipped('ext too old: no session support');
        }

        $uri = getenv('MONGODB_RS_URI');
        if ($uri === false || $uri === '') {
            self::markTestSkipped('MONGODB_RS_URI not set (transactions need a replica set)');
        }

        try {
            self::$client = new Client($uri);
            self::$client->selectDatabase('admin')->command(['ping' => 1]);
        } catch (Throwable $e) {
            self::markTestSkipped('replica set unreachable: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->col = self::$client->selectCollection(self::$db, uniqid('txn_'));
    }

    protected function tearDown(): void
    {
        $this->col->drop();
    }

    public function testCommittedTransactionIsVisible(): void
    {
        $session = self::$client->startSession();
        $session->startTransaction();
        $this->col->insertOne(['flow' => 'commit', 'n' => 1], ['session' => $session]);
        $this->col->insertOne(['flow' => 'commit', 'n' => 2], ['session' => $session]);
        $session->commitTransaction();
        $session->endSession();

        $this->assertSame(2, $this->col->countDocuments(['flow' => 'commit']));
    }

    public function testAbortedTransactionIsInvisible(): void
    {
        $session = self::$client->startSession();
        $session->startTransaction();
        $this->col->insertOne(['flow' => 'abort'], ['session' => $session]);

        // Inside the txn, the SAME session sees the uncommitted write…
        $inTxn = $this->col->countDocuments(['flow' => 'abort'], ['session' => $session]);
        $this->assertSame(1, $inTxn, 'session must read its own uncommitted write');

        $session->abortTransaction();
        $session->endSession();

        // …and after abort, nothing was ever written.
        $this->assertSame(0, $this->col->countDocuments(['flow' => 'abort']));
    }

    public function testTransactionalReadsUseTheSessionSnapshot(): void
    {
        $this->col->insertOne(['k' => 'pre']);

        $session = self::$client->startSession();
        $session->startTransaction();
        $this->col->updateOne(['k' => 'pre'], ['$set' => ['v' => 'txn']], ['session' => $session]);

        $cursor = $this->col->find(['k' => 'pre'], ['session' => $session]);
        $this->assertInstanceOf(ArrayCursor::class, $cursor);
        $docs = iterator_to_array($cursor);
        $this->assertCount(1, $docs);
        $this->assertSame('txn', $docs[0]['v'] ?? null, 'in-txn find must see the in-txn update');

        // Outside the session the update is not committed yet.
        $outside = $this->col->findOne(['k' => 'pre']);
        $this->assertArrayNotHasKey('v', (array) $outside);

        $session->abortTransaction();
        $session->endSession();
    }

    public function testTransactionStateMirror(): void
    {
        $session = self::$client->startSession();
        $this->assertSame(Session::TRANSACTION_NONE, $session->getTransactionState());

        $session->startTransaction();
        $this->assertTrue($session->isInTransaction());

        $this->col->insertOne(['s' => 1], ['session' => $session]);
        $session->commitTransaction();
        $this->assertSame(Session::TRANSACTION_COMMITTED, $session->getTransactionState());
        $this->assertFalse($session->isInTransaction());
        $session->endSession();
    }

    public function testLogicalSessionIdIsReal(): void
    {
        $session = self::$client->startSession();
        $lsid = $session->getLogicalSessionId();
        $this->assertIsObject($lsid);
        $this->assertNotEmpty((array) $lsid, 'lsid must be the driver document, not a fake');
        $session->endSession();
    }

    public function testOperationTimeAdvancesAfterWrite(): void
    {
        $session = self::$client->startSession();
        $this->col->insertOne(['t' => 1], ['session' => $session]);
        $ot = $session->getOperationTime();
        $this->assertIsObject($ot);
        $this->assertGreaterThan(0, $ot->t ?? 0);
        $session->endSession();
    }

    public function testEndedSessionRefusesWork(): void
    {
        $session = self::$client->startSession();
        $session->endSession();
        $this->expectException(RuntimeException::class);
        $session->startTransaction();
    }
}
