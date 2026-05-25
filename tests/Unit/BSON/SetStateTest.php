<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\ObjectId;
use ZealPHP\MongoDB\BSON\PackedArray;
use ZealPHP\MongoDB\BSON\Regex;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\BSON\UTCDateTime;

/**
 * Tests __set_state() across all BSON types that implement it.
 */
class SetStateTest extends TestCase
{
    public function testObjectIdSetState(): void
    {
        $oid = ObjectId::__set_state(['id' => '507f1f77bcf86cd799439011']);
        $this->assertInstanceOf(ObjectId::class, $oid);
        $this->assertSame('507f1f77bcf86cd799439011', (string) $oid);
    }

    public function testBinarySetState(): void
    {
        $bin = Binary::__set_state(['data' => "\x00\x01", 'type' => Binary::TYPE_GENERIC]);
        $this->assertInstanceOf(Binary::class, $bin);
        $this->assertSame("\x00\x01", $bin->getData());
        $this->assertSame(0, $bin->getType());
    }

    public function testDecimal128SetState(): void
    {
        $dec = Decimal128::__set_state(['value' => '1.23']);
        $this->assertInstanceOf(Decimal128::class, $dec);
        $this->assertSame('1.23', (string) $dec);
    }

    public function testRegexSetState(): void
    {
        $regex = Regex::__set_state(['pattern' => 'abc', 'flags' => 'i']);
        $this->assertInstanceOf(Regex::class, $regex);
        $this->assertSame('abc', $regex->getPattern());
        $this->assertSame('i', $regex->getFlags());
        $this->assertSame('/abc/i', (string) $regex);
    }

    public function testTimestampSetState(): void
    {
        $ts = Timestamp::__set_state(['increment' => 1, 'timestamp' => 100]);
        $this->assertInstanceOf(Timestamp::class, $ts);
        $this->assertSame(1, $ts->getIncrement());
        $this->assertSame(100, $ts->getTimestamp());
        $this->assertSame('[100:1]', (string) $ts);
    }

    public function testUTCDateTimeSetState(): void
    {
        $utc = UTCDateTime::__set_state(['milliseconds' => 1234567890000]);
        $this->assertInstanceOf(UTCDateTime::class, $utc);
        $this->assertSame('1234567890000', (string) $utc);
    }

    public function testMinKeySetState(): void
    {
        $minKey = MinKey::__set_state([]);
        $this->assertInstanceOf(MinKey::class, $minKey);
        $this->assertSame(['$minKey' => 1], $minKey->jsonSerialize());
    }

    public function testMaxKeySetState(): void
    {
        $maxKey = MaxKey::__set_state([]);
        $this->assertInstanceOf(MaxKey::class, $maxKey);
        $this->assertSame(['$maxKey' => 1], $maxKey->jsonSerialize());
    }

    public function testInt64SetState(): void
    {
        $int64 = Int64::__set_state(['value' => 9876543210]);
        $this->assertInstanceOf(Int64::class, $int64);
        $this->assertSame('9876543210', (string) $int64);
    }

    public function testPackedArraySetState(): void
    {
        $arr = PackedArray::__set_state(['data' => ['a', 'b', 'c']]);
        $this->assertInstanceOf(PackedArray::class, $arr);
        $this->assertSame(['a', 'b', 'c'], $arr->toPHP());
    }

    public function testJavascriptSetState(): void
    {
        $js = Javascript::__set_state(['code' => 'return x', 'scope' => ['x' => 42]]);
        $this->assertInstanceOf(Javascript::class, $js);
        $this->assertSame('return x', $js->getCode());
        $scope = $js->getScope();
        $this->assertIsObject($scope);
        $this->assertSame(42, $scope->x);
    }

    public function testJavascriptSetStateWithoutScope(): void
    {
        $js = Javascript::__set_state(['code' => 'return 1']);
        $this->assertInstanceOf(Javascript::class, $js);
        $this->assertNull($js->getScope());
    }

    public function testBinarySetStateWithUUIDType(): void
    {
        $bin = Binary::__set_state(['data' => 'uuid-data-here', 'type' => Binary::TYPE_UUID]);
        $this->assertInstanceOf(Binary::class, $bin);
        $this->assertSame('uuid-data-here', $bin->getData());
        $this->assertSame(4, $bin->getType());
    }
}
