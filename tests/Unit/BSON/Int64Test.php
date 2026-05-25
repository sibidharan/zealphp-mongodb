<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Stringable;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Type;

use function json_encode;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

class Int64Test extends TestCase
{
    public function testConstructFromInt(): void
    {
        $int64 = new Int64(42);
        $this->assertSame('42', (string) $int64);
    }

    public function testConstructFromString(): void
    {
        $int64 = new Int64('123');
        $this->assertSame('123', (string) $int64);
    }

    public function testToString(): void
    {
        $int64 = new Int64(0);
        $this->assertSame('0', (string) $int64);
    }

    public function testJsonSerialize(): void
    {
        $int64 = new Int64(99);
        $this->assertSame(99, $int64->jsonSerialize());
    }

    public function testSetState(): void
    {
        $int64 = Int64::__set_state(['value' => 55]);
        $this->assertInstanceOf(Int64::class, $int64);
        $this->assertSame('55', (string) $int64);
    }

    public function testImplementsInterfaces(): void
    {
        $int64 = new Int64(1);
        $this->assertInstanceOf(JsonSerializable::class, $int64);
        $this->assertInstanceOf(Type::class, $int64);
        $this->assertInstanceOf(Stringable::class, $int64);
    }

    public function testLargeValues(): void
    {
        $large = PHP_INT_MAX;
        $int64 = new Int64($large);
        $this->assertSame((string) PHP_INT_MAX, (string) $int64);

        $negative = PHP_INT_MIN;
        $int64Neg = new Int64($negative);
        $this->assertSame((string) PHP_INT_MIN, (string) $int64Neg);
    }

    public function testJsonEncodeOutput(): void
    {
        $int64 = new Int64(42);
        $this->assertSame('42', json_encode($int64));
    }
}
