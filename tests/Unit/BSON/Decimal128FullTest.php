<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Decimal128;

use function json_encode;

class Decimal128FullTest extends TestCase
{
    public function testJsonSerializeFormat(): void
    {
        $dec = new Decimal128('1234.5678');
        $json = $dec->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertArrayHasKey('$numberDecimal', $json);
        $this->assertSame('1234.5678', $json['$numberDecimal']);
    }

    public function testJsonEncodeProducesCorrectString(): void
    {
        $dec = new Decimal128('99.99');
        $this->assertSame('{"$numberDecimal":"99.99"}', json_encode($dec));
    }

    public function testSetState(): void
    {
        $dec = Decimal128::__set_state(['value' => '3.14159']);

        $this->assertInstanceOf(Decimal128::class, $dec);
        $this->assertSame('3.14159', (string) $dec);
    }

    public function testSpecialValues(): void
    {
        $inf = new Decimal128('Infinity');
        $this->assertSame('Infinity', (string) $inf);
        $this->assertSame(['$numberDecimal' => 'Infinity'], $inf->jsonSerialize());

        $nan = new Decimal128('NaN');
        $this->assertSame('NaN', (string) $nan);
        $this->assertSame(['$numberDecimal' => 'NaN'], $nan->jsonSerialize());
    }
}
