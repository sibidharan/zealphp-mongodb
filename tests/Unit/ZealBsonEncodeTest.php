<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\BSON\Binary as OfficialBinary;
use MongoDB\BSON\Decimal128 as OfficialDecimal128;
use MongoDB\BSON\Javascript as OfficialJavascript;
use MongoDB\BSON\MaxKey as OfficialMaxKey;
use MongoDB\BSON\MinKey as OfficialMinKey;
use MongoDB\BSON\Timestamp as OfficialTimestamp;
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
 * #6 / #45: the ZealPHP\MongoDB\BSON\* value objects must encode through
 * prepareBSON to a real BSON value the ext understands. Since #45 that is the
 * official MongoDB\BSON\* object (which the ext encodes by class) rather than an
 * extended-JSON array — returning the value lost (the original #6 bug) or
 * flattening to a collidable array (the #45 bug) are both wrong.
 */
class ZealBsonEncodeTest extends TestCase
{
    public function testBinaryEncodes(): void
    {
        $r = Collection::prepareBSON(new Binary('hello', 0));
        $this->assertInstanceOf(OfficialBinary::class, $r);
        $this->assertSame('hello', $r->getData());
    }

    public function testDecimal128Encodes(): void
    {
        $r = Collection::prepareBSON(new Decimal128('3.14'));
        $this->assertInstanceOf(OfficialDecimal128::class, $r);
        $this->assertSame('3.14', (string) $r);
    }

    public function testInt64Encodes(): void
    {
        $this->assertSame(9000000000, Collection::prepareBSON(new Int64('9000000000')));
    }

    public function testJavascriptEncodes(): void
    {
        $r = Collection::prepareBSON(new Javascript('return 1;'));
        $this->assertInstanceOf(OfficialJavascript::class, $r);
        $this->assertSame('return 1;', $r->getCode());
    }

    public function testTimestampEncodes(): void
    {
        $r = Collection::prepareBSON(new Timestamp(1, 100));
        $this->assertInstanceOf(OfficialTimestamp::class, $r);
        $this->assertSame(100, $r->getTimestamp());
        $this->assertSame(1, $r->getIncrement());
    }

    public function testMinMaxKeyEncode(): void
    {
        $this->assertInstanceOf(OfficialMinKey::class, Collection::prepareBSON(new MinKey()));
        $this->assertInstanceOf(OfficialMaxKey::class, Collection::prepareBSON(new MaxKey()));
    }
}
