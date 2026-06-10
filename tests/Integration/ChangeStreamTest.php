<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use ZealPHP\MongoDB\ChangeStream;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;

use function extension_loaded;
use function function_exists;
use function getenv;
use function uniqid;

/**
 * Real change streams (C-driver parity). Requires a replica set —
 * MONGODB_RS_URI, same convention as TransactionTest. Skips cleanly
 * otherwise.
 */
class ChangeStreamTest extends TestCase
{
    private static Client $client;
    private static string $db = 'zealphp_cs_test';
    private Collection $col;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        if (! function_exists('zealphp_mongodb_watch')) {
            self::markTestSkipped('ext too old: no change stream support');
        }

        $uri = getenv('MONGODB_RS_URI');
        if ($uri === false || $uri === '') {
            self::markTestSkipped('MONGODB_RS_URI not set (change streams need a replica set)');
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
        $this->col = self::$client->selectCollection(self::$db, uniqid('cs_'));
        // Materialize the collection so the stream has something to attach to.
        $this->col->insertOne(['warmup' => true]);
    }

    protected function tearDown(): void
    {
        $this->col->drop();
    }

    /** Pump $stream until an event arrives or ~$budgetMs elapses. */
    private function awaitEvent(ChangeStream $stream, int $budgetMs = 5000): mixed
    {
        $spent = 0;
        while ($spent < $budgetMs) {
            $stream->next();
            if ($stream->valid()) {
                return $stream->current();
            }

            $spent += 500; // next() itself blocked up to maxAwaitTimeMS
        }

        return null;
    }

    public function testInsertEventIsDelivered(): void
    {
        $stream = $this->col->watch([], ['maxAwaitTimeMS' => 500]);
        $stream->rewind();

        $this->col->insertOne(['msg' => 'hello-stream']);

        $event = $this->awaitEvent($stream);
        $this->assertNotNull($event, 'insert event must arrive');
        $this->assertSame('insert', $event['operationType']);
        $this->assertSame('hello-stream', $event['fullDocument']['msg'] ?? null);
        $this->assertSame(0, $stream->key(), 'key() counts delivered events from 0');
        $stream->close();
    }

    public function testUpdateLookupDeliversFullDocument(): void
    {
        $this->col->insertOne(['k' => 'u1', 'v' => 1]);

        $stream = $this->col->watch([], ['maxAwaitTimeMS' => 500, 'fullDocument' => 'updateLookup']);
        $stream->rewind();

        $this->col->updateOne(['k' => 'u1'], ['$set' => ['v' => 2]]);

        $event = $this->awaitEvent($stream);
        $this->assertNotNull($event);
        $this->assertSame('update', $event['operationType']);
        $this->assertSame(2, $event['fullDocument']['v'] ?? null, 'updateLookup must deliver the post-image');
        $stream->close();
    }

    public function testPipelineFiltersEvents(): void
    {
        $stream = $this->col->watch(
            [['$match' => ['operationType' => 'delete']]],
            ['maxAwaitTimeMS' => 500],
        );
        $stream->rewind();

        $this->col->insertOne(['k' => 'f1']);       // filtered out
        $this->col->deleteOne(['k' => 'f1']);       // matches

        $event = $this->awaitEvent($stream);
        $this->assertNotNull($event);
        $this->assertSame('delete', $event['operationType'], 'pipeline must filter inserts out');
        $stream->close();
    }

    public function testResumeTokenAvailableAfterEvent(): void
    {
        $stream = $this->col->watch([], ['maxAwaitTimeMS' => 500]);
        $stream->rewind();
        $this->col->insertOne(['k' => 'rt']);
        $this->assertNotNull($this->awaitEvent($stream));

        $token = $stream->getResumeToken();
        $this->assertIsObject($token);
        $this->assertNotEmpty((array) $token, 'resume token must be a real server token');
        $stream->close();
    }

    public function testTimeoutPollReturnsInvalid(): void
    {
        $stream = $this->col->watch([], ['maxAwaitTimeMS' => 100]);
        $stream->next(); // nothing written — must time out, not hang
        $this->assertFalse($stream->valid());
        $this->assertNull($stream->current());
        $this->assertNull($stream->key());
        $stream->close();
    }

    public function testDatabaseAndClientScopedWatch(): void
    {
        $dbStream = self::$client->selectDatabase(self::$db)->watch([], ['maxAwaitTimeMS' => 500]);
        $dbStream->rewind();
        $this->col->insertOne(['scope' => 'db']);
        $event = $this->awaitEvent($dbStream);
        $this->assertNotNull($event, 'database-scoped stream must see collection writes');
        $this->assertSame('db', $event['fullDocument']['scope'] ?? null);
        $dbStream->close();
    }
}
