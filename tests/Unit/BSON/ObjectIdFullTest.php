<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\ObjectId;

class ObjectIdFullTest extends TestCase
{
    public function testSetState(): void
    {
        $oid = ObjectId::__set_state(['id' => '507f1f77bcf86cd799439011']);

        $this->assertInstanceOf(ObjectId::class, $oid);
        $this->assertSame('507f1f77bcf86cd799439011', (string) $oid);
    }

    public function testConstructorFromDateTimeInterface(): void
    {
        $dt = new DateTimeImmutable('2021-01-01T00:00:00Z');
        $oid = new ObjectId($dt);

        // The first 8 hex chars encode the timestamp
        $this->assertSame($dt->getTimestamp(), $oid->getTimestamp());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $oid);
    }
}
