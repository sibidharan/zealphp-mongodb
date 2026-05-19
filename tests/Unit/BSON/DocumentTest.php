<?php
namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
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
        $this->expectException(\LogicException::class);
        $doc['a'] = 2;
    }

    public function testImmutableUnset(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->expectException(\LogicException::class);
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
        foreach ($doc as $k => $v) { $keys[] = $k; }
        $this->assertSame(['x', 'y'], $keys);
    }
}
