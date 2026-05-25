<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\UTCDateTime;

use function json_encode;
use function microtime;

class UTCDateTimeFullTest extends TestCase
{
    public function testToDateTimeAccuracy(): void
    {
        // 2021-01-01T00:00:00.000Z = 1609459200000 ms
        $utc = new UTCDateTime(1609459200000);
        $dt = $utc->toDateTime();

        $this->assertSame('2021', $dt->format('Y'));
        $this->assertSame('01', $dt->format('m'));
        $this->assertSame('01', $dt->format('d'));
        $this->assertSame('00', $dt->format('H'));
        $this->assertSame('00', $dt->format('i'));
        $this->assertSame('00', $dt->format('s'));
    }

    public function testToDateTimePreservesMilliseconds(): void
    {
        // 1609459200456 ms = 2021-01-01T00:00:00.456Z
        $utc = new UTCDateTime(1609459200456);
        $dt = $utc->toDateTime();

        $this->assertSame('456', $dt->format('v'));
    }

    public function testToDateTimeImmutableAccuracy(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $dti = $utc->toDateTimeImmutable();

        $this->assertInstanceOf(DateTimeImmutable::class, $dti);
        $this->assertSame('2021-01-01', $dti->format('Y-m-d'));
    }

    public function testJsonSerializeFormat(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $json = $utc->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertArrayHasKey('$date', $json);
        $this->assertIsArray($json['$date']);
        $this->assertArrayHasKey('$numberLong', $json['$date']);
        $this->assertSame('1609459200000', $json['$date']['$numberLong']);
    }

    public function testJsonEncodeProducesCorrectString(): void
    {
        $utc = new UTCDateTime(1609459200000);
        $expected = '{"$date":{"$numberLong":"1609459200000"}}';
        $this->assertSame($expected, json_encode($utc));
    }

    public function testSetState(): void
    {
        $utc = UTCDateTime::__set_state(['milliseconds' => 1609459200000]);

        $this->assertInstanceOf(UTCDateTime::class, $utc);
        $this->assertSame('1609459200000', (string) $utc);
    }

    public function testConstructionFromString(): void
    {
        $utc = new UTCDateTime('1609459200000');
        $this->assertSame('1609459200000', (string) $utc);
    }

    public function testConstructionWithNullUsesCurrentTime(): void
    {
        $before = (int) (microtime(true) * 1000);
        $utc = new UTCDateTime(null);
        $after = (int) (microtime(true) * 1000);

        $ms = (int) (string) $utc;
        $this->assertGreaterThanOrEqual($before, $ms);
        $this->assertLessThanOrEqual($after, $ms);
    }
}
