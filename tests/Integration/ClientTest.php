<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\ChangeStream;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Database;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\Session;
use ZealPHP\MongoDB\WriteConcern;

use function extension_loaded;
use function getenv;
use function uniqid;

class ClientTest extends TestCase
{
    private static Client $client;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        self::$client = new Client(getenv('MONGODB_URI') ?: 'mongodb://db.selfmade.ninja:27017');
    }

    public function testListDatabaseNames(): void
    {
        $names = self::$client->listDatabaseNames();
        $this->assertIsArray($names);
        $this->assertContains('admin', $names);
    }

    public function testListDatabases(): void
    {
        $dbs = self::$client->listDatabases();
        $this->assertIsArray($dbs);
        $this->assertNotEmpty($dbs);
    }

    public function testSelectDatabase(): void
    {
        $db = self::$client->selectDatabase('test');
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testMagicGetDatabase(): void
    {
        $db = self::$client->test;
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testSelectCollection(): void
    {
        $col = self::$client->selectCollection('test', 'foo');
        $this->assertInstanceOf(Collection::class, $col);
        $this->assertSame('test.foo', $col->getNamespace());
    }

    public function testStartSession(): void
    {
        $session = self::$client->startSession();
        $this->assertInstanceOf(Session::class, $session);
    }

    public function testWatch(): void
    {
        $cs = self::$client->watch();
        $this->assertInstanceOf(ChangeStream::class, $cs);
        $this->assertFalse($cs->valid());
    }

    public function testConcernGetters(): void
    {
        $this->assertInstanceOf(ReadConcern::class, self::$client->getReadConcern());
        $this->assertInstanceOf(WriteConcern::class, self::$client->getWriteConcern());
        $this->assertInstanceOf(ReadPreference::class, self::$client->getReadPreference());
        $this->assertIsArray(self::$client->getTypeMap());
    }

    public function testToString(): void
    {
        $this->assertSame('mongodb://...', (string) self::$client);
    }

    public function testGetPoolId(): void
    {
        $this->assertIsInt(self::$client->getPoolId());
    }

    public function testDebugInfo(): void
    {
        $info = self::$client->__debugInfo();
        $this->assertArrayHasKey('poolId', $info);
    }

    public function testGetDatabaseAlias(): void
    {
        $db = self::$client->getDatabase('test');
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testGetCollectionAlias(): void
    {
        $col = self::$client->getCollection('test', 'foo');
        $this->assertInstanceOf(Collection::class, $col);
    }

    public function testDropDatabase(): void
    {
        $dbName = 'zealphp_drop_test_' . uniqid();
        $db = self::$client->selectDatabase($dbName);
        $db->selectCollection('tmp')->insertOne(['x' => 1]);
        $result = self::$client->dropDatabase($dbName);
        $this->assertSame(1, $result['ok']);
    }
}
