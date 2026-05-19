<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Decimal128;

class Decimal128Test extends TestCase
{
    public function testConstruction(): void
    {
        $dec = new Decimal128('3.14159');
        $this->assertSame('3.14159', (string) $dec);
    }

    public function testJsonSerialize(): void
    {
        $dec = new Decimal128('3.14');
        $this->assertSame(['$numberDecimal' => '3.14'], $dec->jsonSerialize());
    }
}
