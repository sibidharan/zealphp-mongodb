<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use ArrayObject;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use stdClass;
use ZealPHP\MongoDB\Document;

use function json_encode;

class TopLevelDocumentTest extends TestCase
{
    public function testEmptyConstruction(): void
    {
        $doc = new Document();
        $this->assertCount(0, $doc);
        $this->assertSame([], $doc->getArrayCopy());
    }

    public function testArrayConstructionWithPropertyAccess(): void
    {
        $doc = new Document(['name' => 'Alice', 'age' => 30]);

        // ARRAY_AS_PROPS allows property-style access
        $this->assertSame('Alice', $doc->name);
        $this->assertSame(30, $doc->age);
    }

    public function testArrayAccess(): void
    {
        $doc = new Document(['x' => 1, 'y' => 2]);

        $this->assertSame(1, $doc['x']);
        $this->assertSame(2, $doc['y']);
        $this->assertTrue(isset($doc['x']));
        $this->assertFalse(isset($doc['z']));
    }

    public function testObjectInput(): void
    {
        $obj = new stdClass();
        $obj->foo = 'bar';
        $obj->num = 42;

        $doc = new Document($obj);
        $this->assertSame('bar', $doc['foo']);
        $this->assertSame(42, $doc['num']);
        $this->assertSame('bar', $doc->foo);
    }

    public function testJsonSerializeReturnsArrayCopy(): void
    {
        $input = ['a' => 1, 'b' => 'two', 'c' => [3, 4]];
        $doc = new Document($input);

        $serialized = $doc->jsonSerialize();
        $this->assertSame($input, $serialized);
        $this->assertIsArray($serialized);
    }

    public function testDebugInfoReturnsArrayCopy(): void
    {
        $input = ['key' => 'value', 'nested' => ['inner' => true]];
        $doc = new Document($input);

        $debugInfo = $doc->__debugInfo();
        $this->assertSame($input, $debugInfo);
        $this->assertIsArray($debugInfo);
    }

    public function testModificationViaArrayAccess(): void
    {
        $doc = new Document(['x' => 1]);
        $doc['x'] = 99;
        $doc['y'] = 'new';

        $this->assertSame(99, $doc['x']);
        $this->assertSame('new', $doc['y']);
    }

    public function testGetArrayCopyReturnsPlainArray(): void
    {
        $input = ['alpha' => 'a', 'beta' => 'b'];
        $doc = new Document($input);

        $copy = $doc->getArrayCopy();
        $this->assertIsArray($copy);
        $this->assertSame($input, $copy);

        // Modifying the copy should not affect the document
        $copy['alpha'] = 'changed';
        $this->assertSame('a', $doc['alpha']);
    }

    public function testImplementsJsonSerializable(): void
    {
        $doc = new Document(['k' => 'v']);
        $this->assertInstanceOf(JsonSerializable::class, $doc);
    }

    public function testExtendsArrayObject(): void
    {
        $doc = new Document([]);
        $this->assertInstanceOf(ArrayObject::class, $doc);
    }

    public function testJsonEncodeProducesExpectedOutput(): void
    {
        $doc = new Document(['id' => 1, 'name' => 'test']);
        $json = json_encode($doc);
        $this->assertSame('{"id":1,"name":"test"}', $json);
    }

    public function testCountable(): void
    {
        $doc = new Document(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertCount(3, $doc);
    }

    public function testIterable(): void
    {
        $doc = new Document(['x' => 10, 'y' => 20]);
        $collected = [];
        foreach ($doc as $key => $value) {
            $collected[$key] = $value;
        }

        $this->assertSame(['x' => 10, 'y' => 20], $collected);
    }

    public function testUnsetViaArrayAccess(): void
    {
        $doc = new Document(['a' => 1, 'b' => 2]);
        unset($doc['a']);

        $this->assertFalse(isset($doc['a']));
        $this->assertCount(1, $doc);
        $this->assertSame(['b' => 2], $doc->getArrayCopy());
    }

    public function testPropertyModification(): void
    {
        $doc = new Document(['name' => 'old']);
        $doc->name = 'new';
        $this->assertSame('new', $doc->name);
        $this->assertSame('new', $doc['name']);
    }
}
