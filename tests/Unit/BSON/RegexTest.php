<?php
namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Regex;
use ZealPHP\MongoDB\BSON\RegexInterface;
use ZealPHP\MongoDB\BSON\Type;

class RegexTest extends TestCase
{
    public function testConstruction(): void
    {
        $re = new Regex('^test', 'i');
        $this->assertSame('^test', $re->getPattern());
        $this->assertSame('i', $re->getFlags());
    }

    public function testDefaultEmptyFlags(): void
    {
        $re = new Regex('pattern');
        $this->assertSame('', $re->getFlags());
    }

    public function testToString(): void
    {
        $re = new Regex('^test', 'im');
        $this->assertSame('/^test/im', (string) $re);
    }

    public function testImplementsInterfaces(): void
    {
        $re = new Regex('test');
        $this->assertInstanceOf(RegexInterface::class, $re);
        $this->assertInstanceOf(Type::class, $re);
    }

    public function testJsonSerialize(): void
    {
        $re = new Regex('^test', 'i');
        $expected = ['$regex' => '^test', '$options' => 'i'];
        $this->assertSame($expected, $re->jsonSerialize());
    }
}
