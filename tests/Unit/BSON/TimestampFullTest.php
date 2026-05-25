<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Timestamp;

use function json_encode;

class TimestampFullTest extends TestCase
{
    public function testJsonSerializeFormat(): void
    {
        $ts = new Timestamp(5, 1609459200);
        $json = $ts->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertArrayHasKey('$timestamp', $json);
        $this->assertSame(1609459200, $json['$timestamp']['t']);
        $this->assertSame(5, $json['$timestamp']['i']);
    }

    public function testJsonEncodeProducesCorrectString(): void
    {
        $ts = new Timestamp(1, 100);
        $expected = '{"$timestamp":{"t":100,"i":1}}';
        $this->assertSame($expected, json_encode($ts));
    }

    public function testSetState(): void
    {
        $ts = Timestamp::__set_state(['increment' => 7, 'timestamp' => 1609459200]);

        $this->assertInstanceOf(Timestamp::class, $ts);
        $this->assertSame(7, $ts->getIncrement());
        $this->assertSame(1609459200, $ts->getTimestamp());
    }

    public function testToStringFormat(): void
    {
        $ts = new Timestamp(42, 1609459200);
        $this->assertSame('[1609459200:42]', (string) $ts);
    }

    public function testToStringWithZeroValues(): void
    {
        $ts = new Timestamp(0, 0);
        $this->assertSame('[0:0]', (string) $ts);
    }

    public function testConstructionFromStringParameters(): void
    {
        $ts = new Timestamp('3', '200');
        $this->assertSame(3, $ts->getIncrement());
        $this->assertSame(200, $ts->getTimestamp());
    }
}
