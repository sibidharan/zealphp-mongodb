<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\Regex;

use function json_encode;

class RegexFullTest extends TestCase
{
    public function testJsonSerializeFormat(): void
    {
        $re = new Regex('^hello$', 'im');
        $json = $re->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertArrayHasKey('$regex', $json);
        $this->assertArrayHasKey('$options', $json);
        $this->assertSame('^hello$', $json['$regex']);
        $this->assertSame('im', $json['$options']);
    }

    public function testJsonSerializeWithEmptyFlags(): void
    {
        $re = new Regex('pattern');
        $json = $re->jsonSerialize();

        $this->assertSame('pattern', $json['$regex']);
        $this->assertSame('', $json['$options']);
    }

    public function testJsonEncodeProducesCorrectString(): void
    {
        $re = new Regex('^test', 'i');
        $expected = '{"$regex":"^test","$options":"i"}';
        $this->assertSame($expected, json_encode($re));
    }

    public function testSetState(): void
    {
        $re = Regex::__set_state(['pattern' => 'foo.*bar', 'flags' => 'gis']);

        $this->assertInstanceOf(Regex::class, $re);
        $this->assertSame('foo.*bar', $re->getPattern());
        $this->assertSame('gis', $re->getFlags());
        $this->assertSame('/foo.*bar/gis', (string) $re);
    }

    public function testToStringWithNoFlags(): void
    {
        $re = new Regex('simple');
        $this->assertSame('/simple/', (string) $re);
    }
}
