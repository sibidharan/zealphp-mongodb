<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use InvalidArgumentException;
use LogicException;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;
use stdClass;
use ZealPHP\MongoDB\BSON\Document;

class DocumentTest extends TestCase
{
    public function testFromPHP(): void
    {
        $doc = Document::fromPHP(['a' => 1, 'b' => 'two']);
        $this->assertSame(1, $doc->get('a'));
        $this->assertSame('two', $doc->get('b'));
    }

    public function testFromJSON(): void
    {
        $doc = Document::fromJSON('{"x": 42}');
        $this->assertSame(42, $doc->get('x'));
    }

    public function testHas(): void
    {
        $doc = Document::fromPHP(['key' => 'val']);
        $this->assertTrue($doc->has('key'));
        $this->assertFalse($doc->has('missing'));
    }

    public function testArrayAccess(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->assertSame(1, $doc['a']);
        $this->assertTrue(isset($doc['a']));
    }

    public function testImmutableSet(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->expectException(LogicException::class);
        $doc['a'] = 2;
    }

    public function testImmutableUnset(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->expectException(LogicException::class);
        unset($doc['a']);
    }

    public function testCountable(): void
    {
        $doc = Document::fromPHP(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertCount(3, $doc);
    }

    public function testIterable(): void
    {
        $doc = Document::fromPHP(['x' => 1, 'y' => 2]);
        $keys = [];
        foreach ($doc as $k => $v) {
            $keys[] = $k;
        }

        $this->assertSame(['x', 'y'], $keys);
    }

    public function testFromPHPWithObject(): void
    {
        $obj = new stdClass();
        $obj->name = 'test';
        $obj->value = 42;
        $doc = Document::fromPHP($obj);
        $this->assertSame('test', $doc->get('name'));
        $this->assertSame(42, $doc->get('value'));
    }

    public function testFromJSONInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Document::fromJSON('{invalid json}');
    }

    public function testGetMissingKeyThrows(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->expectException(OutOfRangeException::class);
        $doc->get('missing');
    }

    public function testToPHP(): void
    {
        $doc = Document::fromPHP(['x' => 1, 'y' => 'two']);
        $obj = $doc->toPHP();
        $this->assertIsObject($obj);
        $this->assertSame(1, $obj->x);
        $this->assertSame('two', $obj->y);
    }

    public function testToCanonicalExtendedJSON(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->assertSame('{"a":1}', $doc->toCanonicalExtendedJSON());
    }

    public function testToRelaxedExtendedJSON(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->assertSame('{"a":1}', $doc->toRelaxedExtendedJSON());
    }

    public function testToString(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->assertSame('{"a":1}', (string) $doc);
    }

    public function testSetState(): void
    {
        $doc = Document::__set_state(['data' => ['x' => 10]]);
        $this->assertSame(10, $doc->get('x'));
    }

    public function testOffsetGetMissing(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->assertNull($doc['missing']);
    }
}
