<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;

use function json_encode;

class MinKeyMaxKeyFullTest extends TestCase
{
    public function testMinKeyJsonSerializeStructure(): void
    {
        $minKey = new MinKey();
        $json = $minKey->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertCount(1, $json);
        $this->assertArrayHasKey('$minKey', $json);
        $this->assertSame(1, $json['$minKey']);
    }

    public function testMinKeyJsonEncode(): void
    {
        $minKey = new MinKey();
        $this->assertSame('{"$minKey":1}', json_encode($minKey));
    }

    public function testMaxKeyJsonSerializeStructure(): void
    {
        $maxKey = new MaxKey();
        $json = $maxKey->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertCount(1, $json);
        $this->assertArrayHasKey('$maxKey', $json);
        $this->assertSame(1, $json['$maxKey']);
    }

    public function testMaxKeyJsonEncode(): void
    {
        $maxKey = new MaxKey();
        $this->assertSame('{"$maxKey":1}', json_encode($maxKey));
    }
}
