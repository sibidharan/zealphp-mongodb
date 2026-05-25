<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Binary;

use function base64_encode;
use function random_bytes;

class BinaryFullTest extends TestCase
{
    public function testSetState(): void
    {
        $bin = Binary::__set_state(['data' => 'hello', 'type' => Binary::TYPE_GENERIC]);

        $this->assertInstanceOf(Binary::class, $bin);
        $this->assertSame('hello', $bin->getData());
        $this->assertSame(Binary::TYPE_GENERIC, $bin->getType());
    }

    public function testJsonSerializeUuidSubtype(): void
    {
        $data = random_bytes(16);
        $bin = new Binary($data, Binary::TYPE_UUID);
        $json = $bin->jsonSerialize();

        $this->assertSame('04', $json['$binary']['subType']);
        $this->assertSame(base64_encode($data), $json['$binary']['base64']);
    }

    public function testJsonSerializeMd5Subtype(): void
    {
        $data = 'd41d8cd98f00b204e9800998ecf8427e';
        $bin = new Binary($data, Binary::TYPE_MD5);
        $json = $bin->jsonSerialize();

        $this->assertSame('05', $json['$binary']['subType']);
        $this->assertSame(base64_encode($data), $json['$binary']['base64']);
    }

    public function testJsonSerializeUserDefinedSubtype(): void
    {
        $bin = new Binary('custom', Binary::TYPE_USER_DEFINED);
        $json = $bin->jsonSerialize();

        // TYPE_USER_DEFINED = 128 = 0x80
        $this->assertSame('80', $json['$binary']['subType']);
        $this->assertSame(base64_encode('custom'), $json['$binary']['base64']);
    }
}
