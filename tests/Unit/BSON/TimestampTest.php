<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\BSON\TimestampInterface;

class TimestampTest extends TestCase
{
    public function testConstruction(): void
    {
        $ts = new Timestamp(1, 1609459200);
        $this->assertSame(1609459200, $ts->getTimestamp());
        $this->assertSame(1, $ts->getIncrement());
    }

    public function testIncrementFirstParameterOrder(): void
    {
        $ts = new Timestamp(42, 100);
        $this->assertSame(42, $ts->getIncrement());
        $this->assertSame(100, $ts->getTimestamp());
    }

    public function testImplementsInterface(): void
    {
        $ts = new Timestamp(0, 0);
        $this->assertInstanceOf(TimestampInterface::class, $ts);
    }

    public function testJsonSerialize(): void
    {
        $ts = new Timestamp(1, 100);
        $json = $ts->jsonSerialize();
        $this->assertSame(['$timestamp' => ['t' => 100, 'i' => 1]], $json);
    }
}
