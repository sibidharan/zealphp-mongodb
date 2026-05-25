<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use ArrayAccess;
use Iterator;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\ArrayCursor;
use ZealPHP\MongoDB\Document;

use function is_array;

class ArrayCursorTest extends TestCase
{
    public function testImplementsIterator(): void
    {
        $cursor = new ArrayCursor([]);
        $this->assertInstanceOf(Iterator::class, $cursor);
    }

    public function testEmptyArrayConstruction(): void
    {
        $cursor = new ArrayCursor([]);
        $this->assertFalse($cursor->valid());
        $this->assertNull($cursor->current());
        $this->assertSame([], $cursor->toArray());
    }

    public function testCurrentReturnsFirstDocument(): void
    {
        $docs   = [['name' => 'Alice'], ['name' => 'Bob']];
        $cursor = new ArrayCursor($docs);

        $current = $cursor->current();
        $this->assertInstanceOf(ArrayAccess::class, $current);
        $this->assertSame('Alice', $current['name']);
    }

    public function testKeyReturnsPosition(): void
    {
        $cursor = new ArrayCursor([['a' => 1], ['a' => 2]]);
        $this->assertSame(0, $cursor->key());
        $cursor->next();
        $this->assertSame(1, $cursor->key());
    }

    public function testNextAdvancesPosition(): void
    {
        $cursor = new ArrayCursor([['x' => 1], ['x' => 2], ['x' => 3]]);
        $cursor->next();
        $this->assertSame(1, $cursor->key());
        $current = $cursor->current();
        $this->assertInstanceOf(ArrayAccess::class, $current);
        $this->assertSame(2, $current['x']);
    }

    public function testRewindResetsToStart(): void
    {
        $cursor = new ArrayCursor([['v' => 10], ['v' => 20]]);
        $cursor->next();
        $cursor->next();
        $this->assertFalse($cursor->valid());

        $cursor->rewind();
        $this->assertTrue($cursor->valid());
        $this->assertSame(0, $cursor->key());
        $this->assertSame(10, $cursor->current()['v']);
    }

    public function testValidReturnsTrueForExistingPositions(): void
    {
        $cursor = new ArrayCursor([['a' => 1]]);
        $this->assertTrue($cursor->valid());
        $cursor->next();
        $this->assertFalse($cursor->valid());
    }

    public function testForeachIteration(): void
    {
        $docs   = [['name' => 'A'], ['name' => 'B'], ['name' => 'C']];
        $cursor = new ArrayCursor($docs);

        $collected = [];
        foreach ($cursor as $key => $doc) {
            $collected[$key] = $doc['name'];
        }

        $this->assertSame([0 => 'A', 1 => 'B', 2 => 'C'], $collected);
    }

    public function testToArrayReturnsAllDocuments(): void
    {
        $docs   = [['id' => 1], ['id' => 2], ['id' => 3]];
        $cursor = new ArrayCursor($docs);
        $arr    = $cursor->toArray();

        $this->assertCount(3, $arr);
        $this->assertInstanceOf(ArrayAccess::class, $arr[0]);
        $this->assertSame(1, $arr[0]['id']);
        $this->assertSame(3, $arr[2]['id']);
    }

    public function testSortAscendingSingleField(): void
    {
        $docs   = [['score' => 30], ['score' => 10], ['score' => 20]];
        $cursor = new ArrayCursor($docs);
        $cursor->sort(['score' => 1]);

        $arr = $cursor->toArray();
        $this->assertSame(10, $arr[0]['score']);
        $this->assertSame(20, $arr[1]['score']);
        $this->assertSame(30, $arr[2]['score']);
    }

    public function testSortDescendingSingleField(): void
    {
        $docs   = [['score' => 10], ['score' => 30], ['score' => 20]];
        $cursor = new ArrayCursor($docs);
        $cursor->sort(['score' => -1]);

        $arr = $cursor->toArray();
        $this->assertSame(30, $arr[0]['score']);
        $this->assertSame(20, $arr[1]['score']);
        $this->assertSame(10, $arr[2]['score']);
    }

    public function testSortMultiFieldTieBreaking(): void
    {
        $docs = [
            ['group' => 'B', 'rank' => 2],
            ['group' => 'A', 'rank' => 3],
            ['group' => 'A', 'rank' => 1],
            ['group' => 'B', 'rank' => 1],
        ];

        $cursor = new ArrayCursor($docs);
        $cursor->sort(['group' => 1, 'rank' => 1]);

        $arr = $cursor->toArray();
        $this->assertSame('A', $arr[0]['group']);
        $this->assertSame(1, $arr[0]['rank']);
        $this->assertSame('A', $arr[1]['group']);
        $this->assertSame(3, $arr[1]['rank']);
        $this->assertSame('B', $arr[2]['group']);
        $this->assertSame(1, $arr[2]['rank']);
        $this->assertSame('B', $arr[3]['group']);
        $this->assertSame(2, $arr[3]['rank']);
    }

    public function testLimitReducesDocumentCount(): void
    {
        $docs   = [['i' => 1], ['i' => 2], ['i' => 3], ['i' => 4], ['i' => 5]];
        $cursor = new ArrayCursor($docs);
        $cursor->limit(3);

        $arr = $cursor->toArray();
        $this->assertCount(3, $arr);
        $this->assertSame(1, $arr[0]['i']);
        $this->assertSame(3, $arr[2]['i']);
    }

    public function testSkipSkipsFirstNDocuments(): void
    {
        $docs   = [['i' => 1], ['i' => 2], ['i' => 3], ['i' => 4]];
        $cursor = new ArrayCursor($docs);
        $cursor->skip(2);

        $arr = $cursor->toArray();
        $this->assertCount(2, $arr);
        $this->assertSame(3, $arr[0]['i']);
        $this->assertSame(4, $arr[1]['i']);
    }

    public function testChainingSortLimitSkip(): void
    {
        $docs = [
            ['val' => 50],
            ['val' => 10],
            ['val' => 40],
            ['val' => 20],
            ['val' => 30],
        ];

        $cursor = new ArrayCursor($docs);
        $result = $cursor->sort(['val' => 1])->skip(1)->limit(2);

        $this->assertInstanceOf(ArrayCursor::class, $result);
        $arr = $result->toArray();
        $this->assertCount(2, $arr);
        // After sort asc: 10,20,30,40,50 -> skip(1): 20,30,40,50 -> limit(2): 20,30
        $this->assertSame(20, $arr[0]['val']);
        $this->assertSame(30, $arr[1]['val']);
    }

    public function testConstructorWrapsAssociativeArraysAsDocument(): void
    {
        $docs   = [['name' => 'Alice', 'age' => 30]];
        $cursor = new ArrayCursor($docs);

        $doc = $cursor->current();
        // Associative arrays are wrapped via Collection::wrapDoc() into Document objects
        $this->assertInstanceOf(ArrayAccess::class, $doc);
        $this->assertFalse(is_array($doc), 'Document should not be a plain array');
        $this->assertSame('Alice', $doc['name']);
        $this->assertSame(30, $doc['age']);
    }

    public function testConstructorPassesThroughListArraysUnchanged(): void
    {
        $listArray = [1, 2, 3];
        $cursor    = new ArrayCursor([$listArray]);

        $doc = $cursor->current();
        // A list array is not an associative array, so it passes through without wrapping
        $this->assertIsArray($doc);
        $this->assertSame([1, 2, 3], $doc);
    }

    public function testConstructorPassesThroughNonArrayValues(): void
    {
        $doc    = new Document(['x' => 1]);
        $cursor = new ArrayCursor([$doc]);

        $this->assertSame($doc, $cursor->current());
    }

    public function testEmptyAfterFullIteration(): void
    {
        $cursor = new ArrayCursor([['a' => 1], ['a' => 2]]);

        // Exhaust the cursor via foreach
        foreach ($cursor as $doc) {
            $count = $doc;
        }

        // After full iteration, valid() should be false
        $this->assertFalse($cursor->valid());
        $this->assertNull($cursor->current());
    }

    public function testSortReturnsSelf(): void
    {
        $cursor = new ArrayCursor([['x' => 1]]);
        $result = $cursor->sort(['x' => 1]);
        $this->assertSame($cursor, $result);
    }

    public function testLimitReturnsSelf(): void
    {
        $cursor = new ArrayCursor([['x' => 1]]);
        $result = $cursor->limit(1);
        $this->assertSame($cursor, $result);
    }

    public function testSkipReturnsSelf(): void
    {
        $cursor = new ArrayCursor([['x' => 1]]);
        $result = $cursor->skip(0);
        $this->assertSame($cursor, $result);
    }

    public function testSortWithMissingFieldUsesNull(): void
    {
        $docs = [
            ['name' => 'Charlie', 'age' => 25],
            ['name' => 'Alice'],
            ['name' => 'Bob', 'age' => 30],
        ];

        $cursor = new ArrayCursor($docs);
        $cursor->sort(['age' => 1]);

        $arr = $cursor->toArray();
        // null sorts before integers in PHP's spaceship operator
        $this->assertSame('Alice', $arr[0]['name']);
        $this->assertSame('Charlie', $arr[1]['name']);
        $this->assertSame('Bob', $arr[2]['name']);
    }

    public function testLimitLargerThanDocCount(): void
    {
        $docs   = [['i' => 1], ['i' => 2]];
        $cursor = new ArrayCursor($docs);
        $cursor->limit(100);

        $this->assertCount(2, $cursor->toArray());
    }

    public function testSkipEntireCollection(): void
    {
        $docs   = [['i' => 1], ['i' => 2]];
        $cursor = new ArrayCursor($docs);
        $cursor->skip(5);

        $this->assertCount(0, $cursor->toArray());
        $this->assertFalse($cursor->valid());
    }

    public function testMultipleForeachRewinds(): void
    {
        $cursor = new ArrayCursor([['v' => 'a'], ['v' => 'b']]);

        $first = [];
        foreach ($cursor as $doc) {
            $first[] = $doc['v'];
        }

        $second = [];
        foreach ($cursor as $doc) {
            $second[] = $doc['v'];
        }

        $this->assertSame($first, $second);
        $this->assertSame(['a', 'b'], $first);
    }

    public function testSortEqualElementsPreservesStability(): void
    {
        $docs = [
            ['score' => 90, 'name' => 'Alice'],
            ['score' => 90, 'name' => 'Bob'],
            ['score' => 90, 'name' => 'Charlie'],
        ];
        $cursor = new ArrayCursor($docs);
        $cursor->sort(['score' => 1]);
        $arr = $cursor->toArray();
        $this->assertCount(3, $arr);
        $this->assertSame(90, $arr[0]['score']);
        $this->assertSame(90, $arr[1]['score']);
        $this->assertSame(90, $arr[2]['score']);
    }
}
