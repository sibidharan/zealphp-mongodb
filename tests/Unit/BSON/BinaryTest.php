<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\BinaryInterface;
use ZealPHP\MongoDB\BSON\Type;

use function random_bytes;

class BinaryTest extends TestCase
{
    public function testConstructionGeneric(): void
    {
        $bin = new Binary("\x00\x01\x02");
        $this->assertSame("\x00\x01\x02", $bin->getData());
        $this->assertSame(Binary::TYPE_GENERIC, $bin->getType());
    }

    public function testConstructionUUID(): void
    {
        $data = random_bytes(16);
        $bin = new Binary($data, Binary::TYPE_UUID);
        $this->assertSame($data, $bin->getData());
        $this->assertSame(4, $bin->getType());
    }

    public function testToString(): void
    {
        $bin = new Binary('hello');
        $this->assertSame('hello', (string) $bin);
    }

    public function testConstants(): void
    {
        $this->assertSame(0, Binary::TYPE_GENERIC);
        $this->assertSame(4, Binary::TYPE_UUID);
        $this->assertSame(5, Binary::TYPE_MD5);
        $this->assertSame(128, Binary::TYPE_USER_DEFINED);
    }

    public function testImplementsInterfaces(): void
    {
        $bin = new Binary('test');
        $this->assertInstanceOf(BinaryInterface::class, $bin);
        $this->assertInstanceOf(Type::class, $bin);
    }

    public function testJsonSerialize(): void
    {
        $bin = new Binary("\x00\x01\x02", Binary::TYPE_GENERIC);
        $json = $bin->jsonSerialize();
        $this->assertArrayHasKey('$binary', $json);
        $this->assertSame('AAEC', $json['$binary']['base64']);
        $this->assertSame('00', $json['$binary']['subType']);
    }
}
