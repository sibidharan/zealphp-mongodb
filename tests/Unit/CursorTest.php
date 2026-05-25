<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use Iterator;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Cursor;

class CursorTest extends TestCase
{
    public function testImplementsIterator(): void
    {
        $cursor = new Cursor(null);
        $this->assertInstanceOf(Iterator::class, $cursor);
    }

    public function testNullCursorIdInitialState(): void
    {
        $cursor = new Cursor(null);
        $this->assertNull($cursor->current());
        $this->assertSame(-1, $cursor->key());
        $this->assertFalse($cursor->valid());
    }

    public function testDeferredFactoryCreatesInstance(): void
    {
        $cursor = Cursor::deferred(1, 'testdb', 'testcol', ['status' => 'active'], null);
        $this->assertInstanceOf(Cursor::class, $cursor);
    }

    public function testDeferredCursorHasNullState(): void
    {
        $cursor = Cursor::deferred(1, 'mydb', 'mycol', [], ['limit' => 10]);

        // A deferred cursor has not yet executed; current should be null and key should be -1
        $this->assertNull($cursor->current());
        $this->assertSame(-1, $cursor->key());
        $this->assertFalse($cursor->valid());
    }

    public function testDeferredWithEmptyFilterAndOptions(): void
    {
        $cursor = Cursor::deferred(0, 'db', 'collection', [], null);
        $this->assertInstanceOf(Cursor::class, $cursor);
        $this->assertInstanceOf(Iterator::class, $cursor);
        $this->assertFalse($cursor->valid());
    }

    public function testNullCursorIdDestructDoesNotError(): void
    {
        // Constructing with null cursorId should allow safe destruction
        // without calling zealphp_mongodb_cursor_close
        $cursor = new Cursor(null);
        unset($cursor);

        // If we reach here without error, the destructor handled null correctly
        $this->assertTrue(true);
    }

    public function testDeferredDestructDoesNotError(): void
    {
        // A deferred cursor that was never materialized should destruct safely
        // because its cursorId is null
        $cursor = Cursor::deferred(1, 'db', 'col', [], null);
        unset($cursor);

        $this->assertTrue(true);
    }
}
