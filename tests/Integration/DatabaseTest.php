<?php
namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Database;

class DatabaseTest extends TestCase
{
    private static Client $client;
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }
        self::$client = new Client(getenv('MONGODB_URI') ?: 'mongodb://db.selfmade.ninja:27017');
    }

    protected function setUp(): void
    {
        $this->db = self::$client->selectDatabase('zealphp_phpunit_' . uniqid());
    }

    protected function tearDown(): void
    {
        try { $this->db->drop(); } catch (\Throwable $e) {}
    }

    public function testCommandPing(): void
    {
        $result = $this->db->command(['ping' => 1]);
        $this->assertArrayHasKey('ok', $result);
        $this->assertEquals(1, $result['ok']);
    }

    public function testCreateAndDropCollection(): void
    {
        $this->db->createCollection('test_col');
        $names = $this->db->listCollectionNames();
        $this->assertContains('test_col', $names);

        $this->db->dropCollection('test_col');
        $names = $this->db->listCollectionNames();
        $this->assertNotContains('test_col', $names);
    }

    public function testSelectCollectionMagicGet(): void
    {
        $col = $this->db->myCollection;
        $this->assertInstanceOf(\ZealPHP\MongoDB\Collection::class, $col);
        $this->assertSame('myCollection', $col->getCollectionName());
    }

    public function testToString(): void
    {
        $this->assertSame($this->db->getDatabaseName(), (string) $this->db);
    }

    public function testSelectGridFSBucket(): void
    {
        $bucket = $this->db->selectGridFSBucket();
        $this->assertInstanceOf(\ZealPHP\MongoDB\GridFS\Bucket::class, $bucket);
        $this->assertSame('fs', $bucket->getBucketName());

        $this->expectException(\ZealPHP\MongoDB\Exception\RuntimeException::class);
        $bucket->find();
    }

    public function testWithOptions(): void
    {
        $new = $this->db->withOptions(['readConcern' => 'local']);
        $this->assertInstanceOf(Database::class, $new);
        $this->assertNotSame($this->db, $new);
    }
}
