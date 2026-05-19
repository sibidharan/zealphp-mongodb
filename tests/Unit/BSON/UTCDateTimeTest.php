<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use DateTime;
use DateTimeImmutable;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Type;
use ZealPHP\MongoDB\BSON\UTCDateTime;
use ZealPHP\MongoDB\BSON\UTCDateTimeInterface;

use function time;

class UTCDateTimeTest extends TestCase
{
    public function testConstructorDefaultsToNow(): void
    {
        $utc = new UTCDateTime();
        $ms = (int) (string) $utc;
        $this->assertEqualsWithDelta(time() * 1000, $ms, 2000);
    }

    public function testConstructorFromMilliseconds(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $this->assertSame('1609459200000', (string) $utc);
    }

    public function testConstructorFromDateTime(): void
    {
        $dt = new DateTime('2021-01-01T00:00:00Z');
        $utc = new UTCDateTime($dt);
        $this->assertGreaterThan(0, (int) (string) $utc);
    }

    public function testToDateTime(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $dt = $utc->toDateTime();
        $this->assertInstanceOf(DateTime::class, $dt);
    }

    public function testToDateTimeImmutable(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $dti = $utc->toDateTimeImmutable();
        $this->assertInstanceOf(DateTimeImmutable::class, $dti);
    }

    public function testImplementsInterfaces(): void
    {
        $utc = new UTCDateTime();
        $this->assertInstanceOf(UTCDateTimeInterface::class, $utc);
        $this->assertInstanceOf(Type::class, $utc);
        $this->assertInstanceOf(JsonSerializable::class, $utc);
    }

    public function testJsonSerialize(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $json = $utc->jsonSerialize();
        $this->assertArrayHasKey('$date', $json);
        $this->assertSame('1609459200000', $json['$date']['$numberLong']);
    }
}
