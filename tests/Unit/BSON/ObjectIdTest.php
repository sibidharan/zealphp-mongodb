<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Stringable;
use ZealPHP\MongoDB\BSON\ObjectId;
use ZealPHP\MongoDB\BSON\ObjectIdInterface;
use ZealPHP\MongoDB\BSON\Type;

use function json_encode;
use function time;

class ObjectIdTest extends TestCase
{
    public function testConstructorGeneratesValidId(): void
    {
        $oid = new ObjectId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $oid);
    }

    public function testConstructorAcceptsValidHexString(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        $oid = new ObjectId($hex);
        $this->assertSame($hex, (string) $oid);
    }

    public function testConstructorRejectsInvalidString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ObjectId('invalid');
    }

    public function testConstructorRejectsTooShortString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ObjectId('507f1f77');
    }

    public function testGetTimestamp(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $this->assertSame(0x507f1f77, $oid->getTimestamp());
    }

    public function testGetTimestampIsRecentForNewId(): void
    {
        $oid = new ObjectId();
        $this->assertEqualsWithDelta(time(), $oid->getTimestamp(), 2);
    }

    public function testImplementsInterfaces(): void
    {
        $oid = new ObjectId();
        $this->assertInstanceOf(ObjectIdInterface::class, $oid);
        $this->assertInstanceOf(Type::class, $oid);
        $this->assertInstanceOf(JsonSerializable::class, $oid);
        $this->assertInstanceOf(Stringable::class, $oid);
    }

    public function testJsonSerialize(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $this->assertSame(['$oid' => '507f1f77bcf86cd799439011'], $oid->jsonSerialize());
    }

    public function testJsonEncode(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $this->assertSame('{"$oid":"507f1f77bcf86cd799439011"}', json_encode($oid));
    }
}
