<?php
namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\Type;

class MinKeyMaxKeyTest extends TestCase
{
    public function testMinKeyJsonSerialize(): void
    {
        $this->assertSame(['$minKey' => 1], (new MinKey())->jsonSerialize());
    }

    public function testMaxKeyJsonSerialize(): void
    {
        $this->assertSame(['$maxKey' => 1], (new MaxKey())->jsonSerialize());
    }

    public function testImplementsType(): void
    {
        $this->assertInstanceOf(Type::class, new MinKey());
        $this->assertInstanceOf(Type::class, new MaxKey());
    }
}
