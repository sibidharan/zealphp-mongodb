<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use ArrayAccess;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;
use Stringable;
use ZealPHP\MongoDB\BSON\PackedArray;
use ZealPHP\MongoDB\BSON\Type;

use function count;
use function iterator_to_array;

class PackedArrayTest extends TestCase
{
    public function testFromPHP(): void
    {
        $arr = PackedArray::fromPHP([10, 20, 30]);
        $this->assertInstanceOf(PackedArray::class, $arr);
        $this->assertSame([10, 20, 30], $arr->toPHP());
    }

    public function testFromPHPReindexes(): void
    {
        // Non-sequential keys should be re-indexed
        $arr = PackedArray::fromPHP([2 => 'a', 5 => 'b', 9 => 'c']);
        $this->assertSame(['a', 'b', 'c'], $arr->toPHP());
    }

    public function testFromJSONValid(): void
    {
        $arr = PackedArray::fromJSON('[1,2,3]');
        $this->assertSame([1, 2, 3], $arr->toPHP());
    }

    public function testFromJSONInvalidJSON(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Error decoding JSON');
        PackedArray::fromJSON('{invalid json');
    }

    public function testFromJSONNonArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON does not represent a sequential array');
        PackedArray::fromJSON('"hello"');
    }

    public function testFromJSONNonListObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON does not represent a sequential array');
        PackedArray::fromJSON('{"a":1,"b":2}');
    }

    public function testGetValid(): void
    {
        $arr = PackedArray::fromPHP(['foo', 'bar', 'baz']);
        $this->assertSame('foo', $arr->get(0));
        $this->assertSame('bar', $arr->get(1));
        $this->assertSame('baz', $arr->get(2));
    }

    public function testGetOutOfRange(): void
    {
        $arr = PackedArray::fromPHP(['a', 'b']);
        $this->expectException(OutOfRangeException::class);
        $arr->get(5);
    }

    public function testHasTrue(): void
    {
        $arr = PackedArray::fromPHP([10, 20]);
        $this->assertTrue($arr->has(0));
        $this->assertTrue($arr->has(1));
    }

    public function testHasFalse(): void
    {
        $arr = PackedArray::fromPHP([10, 20]);
        $this->assertFalse($arr->has(2));
        $this->assertFalse($arr->has(-1));
    }

    public function testToPHP(): void
    {
        $input = [1, 'two', true, null];
        $arr = PackedArray::fromPHP($input);
        $this->assertSame($input, $arr->toPHP());
    }

    public function testToCanonicalExtendedJSON(): void
    {
        $arr = PackedArray::fromPHP([1, 'hello']);
        $this->assertSame('[1,"hello"]', $arr->toCanonicalExtendedJSON());
    }

    public function testToRelaxedExtendedJSON(): void
    {
        $arr = PackedArray::fromPHP([1, 'hello']);
        $this->assertSame('[1,"hello"]', $arr->toRelaxedExtendedJSON());
    }

    public function testCount(): void
    {
        $arr = PackedArray::fromPHP([1, 2, 3, 4, 5]);
        $this->assertSame(5, count($arr));
        $this->assertSame(5, $arr->count());

        $empty = PackedArray::fromPHP([]);
        $this->assertSame(0, count($empty));
    }

    public function testIteration(): void
    {
        $input = ['a', 'b', 'c'];
        $arr = PackedArray::fromPHP($input);
        $result = iterator_to_array($arr);
        $this->assertSame($input, $result);
    }

    public function testOffsetSetThrowsLogicException(): void
    {
        $arr = PackedArray::fromPHP([1, 2]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');
        $arr[0] = 'new';
    }

    public function testOffsetUnsetThrowsLogicException(): void
    {
        $arr = PackedArray::fromPHP([1, 2]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');
        unset($arr[0]);
    }

    public function testSetState(): void
    {
        $arr = PackedArray::__set_state(['data' => [10, 20, 30]]);
        $this->assertInstanceOf(PackedArray::class, $arr);
        $this->assertSame([10, 20, 30], $arr->toPHP());
    }

    public function testImplementsInterfaces(): void
    {
        $arr = PackedArray::fromPHP([]);
        $this->assertInstanceOf(IteratorAggregate::class, $arr);
        $this->assertInstanceOf(ArrayAccess::class, $arr);
        $this->assertInstanceOf(Type::class, $arr);
        $this->assertInstanceOf(Stringable::class, $arr);
        $this->assertInstanceOf(Countable::class, $arr);
    }

    public function testOffsetExistsAndOffsetGet(): void
    {
        $arr = PackedArray::fromPHP(['x', 'y']);
        $this->assertTrue(isset($arr[0]));
        $this->assertTrue(isset($arr[1]));
        $this->assertFalse(isset($arr[2]));
        $this->assertSame('x', $arr[0]);
        $this->assertSame('y', $arr[1]);
    }

    public function testToStringReturnsJSON(): void
    {
        $arr = PackedArray::fromPHP([1, 2, 3]);
        $this->assertSame('[1,2,3]', (string) $arr);
    }
}
