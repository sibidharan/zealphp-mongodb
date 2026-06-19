<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\Collection;

/**
 * #6: the ZealPHP\MongoDB\BSON\* value objects must encode through prepareBSON
 * to extended JSON the Rust ext understands — returning the object unchanged
 * (the bug) means the ext can't serialize it and the value is lost.
 */
class ZealBsonEncodeTest extends TestCase
{
    public function testBinaryEncodes(): void
    {
        $r = Collection::prepareBSON(new Binary('hello', 0));
        $this->assertIsArray($r);
        $this->assertArrayHasKey('$binary', $r);
    }

    public function testDecimal128Encodes(): void
    {
        $r = Collection::prepareBSON(new Decimal128('3.14'));
        $this->assertIsArray($r);
        $this->assertArrayHasKey('$numberDecimal', $r);
    }

    public function testInt64Encodes(): void
    {
        $this->assertSame(9000000000, Collection::prepareBSON(new Int64('9000000000')));
    }

    public function testJavascriptEncodes(): void
    {
        $r = Collection::prepareBSON(new Javascript('return 1;'));
        $this->assertIsArray($r);
        $this->assertArrayHasKey('$code', $r);
    }

    public function testTimestampEncodes(): void
    {
        $r = Collection::prepareBSON(new Timestamp(1, 100));
        $this->assertIsArray($r);
        $this->assertArrayHasKey('$timestamp', $r);
    }

    public function testMinMaxKeyEncode(): void
    {
        $this->assertSame(['$minKey' => 1], Collection::prepareBSON(new MinKey()));
        $this->assertSame(['$maxKey' => 1], Collection::prepareBSON(new MaxKey()));
    }
}
