<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use Iterator;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\ChangeStream;

class ChangeStreamTest extends TestCase
{
    public function testEmptyStream(): void
    {
        $cs = new ChangeStream();
        $this->assertFalse($cs->valid());
        $this->assertNull($cs->current());
        $this->assertNull($cs->getResumeToken());
    }

    public function testImplementsIterator(): void
    {
        $cs = new ChangeStream();
        $this->assertInstanceOf(Iterator::class, $cs);
    }

    public function testKeyReturnsNull(): void
    {
        $cs = new ChangeStream();
        $this->assertNull($cs->key());
    }

    public function testNextDoesNotError(): void
    {
        $cs = new ChangeStream();
        $cs->next();
        $this->assertFalse($cs->valid());
    }

    public function testRewindDoesNotError(): void
    {
        $cs = new ChangeStream();
        $cs->rewind();
        $this->assertFalse($cs->valid());
    }

    public function testForeachProducesNoResults(): void
    {
        $cs = new ChangeStream();
        $results = [];
        foreach ($cs as $item) {
            $results[] = $item;
        }

        $this->assertEmpty($results);
    }
}
