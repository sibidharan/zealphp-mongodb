<?php
namespace ZealPHP\MongoDB\Tests\Unit;

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
        $this->assertInstanceOf(\Iterator::class, $cs);
    }
}
