<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Document;
use ZealPHP\MongoDB\IndexInfo;

class IndexInfoTest extends TestCase
{
    public function testGetName(): void
    {
        $info = new IndexInfo(['name' => '_id_']);
        $this->assertSame('_id_', $info->getName());
    }

    public function testGetNameDefaultsToEmpty(): void
    {
        $info = new IndexInfo([]);
        $this->assertSame('', $info->getName());
    }

    public function testGetKeyWithArray(): void
    {
        $info = new IndexInfo(['key' => ['field1' => 1, 'field2' => -1]]);
        $this->assertSame(['field1' => 1, 'field2' => -1], $info->getKey());
    }

    public function testGetKeyWithDocumentInstance(): void
    {
        $keyDoc = new Document(['_id' => 1]);
        $info = new IndexInfo(['key' => $keyDoc]);
        $this->assertSame(['_id' => 1], $info->getKey());
    }

    public function testGetKeyDefaultsToEmptyArray(): void
    {
        $info = new IndexInfo([]);
        $this->assertSame([], $info->getKey());
    }

    public function testGetNamespace(): void
    {
        $info = new IndexInfo(['ns' => 'mydb.mycollection']);
        $this->assertSame('mydb.mycollection', $info->getNamespace());
    }

    public function testGetNamespaceDefaultsToEmpty(): void
    {
        $info = new IndexInfo([]);
        $this->assertSame('', $info->getNamespace());
    }

    public function testGetVersion(): void
    {
        $info = new IndexInfo(['v' => 2]);
        $this->assertSame(2, $info->getVersion());
    }

    public function testGetVersionDefaultsToZero(): void
    {
        $info = new IndexInfo([]);
        $this->assertSame(0, $info->getVersion());
    }

    public function testIsUniqueTrue(): void
    {
        $info = new IndexInfo(['unique' => true]);
        $this->assertTrue($info->isUnique());
    }

    public function testIsUniqueFalseExplicit(): void
    {
        $info = new IndexInfo(['unique' => false]);
        $this->assertFalse($info->isUnique());
    }

    public function testIsUniqueDefaultsFalse(): void
    {
        $info = new IndexInfo([]);
        $this->assertFalse($info->isUnique());
    }

    public function testIsSparseTrue(): void
    {
        $info = new IndexInfo(['sparse' => true]);
        $this->assertTrue($info->isSparse());
    }

    public function testIsSparseDefaultsFalse(): void
    {
        $info = new IndexInfo([]);
        $this->assertFalse($info->isSparse());
    }

    public function testIsTtlTrue(): void
    {
        $info = new IndexInfo(['expireAfterSeconds' => 3600]);
        $this->assertTrue($info->isTtl());
    }

    public function testIsTtlFalseWhenKeyMissing(): void
    {
        $info = new IndexInfo([]);
        $this->assertFalse($info->isTtl());
    }

    public function testIsTtlTrueWithZeroValue(): void
    {
        // Even when expireAfterSeconds is 0, the key is set so isTtl should be true
        $info = new IndexInfo(['expireAfterSeconds' => 0]);
        $this->assertTrue($info->isTtl());
    }

    public function testExtendsDocument(): void
    {
        $info = new IndexInfo(['name' => 'test_idx']);
        $this->assertInstanceOf(Document::class, $info);
    }

    public function testFullIndexPayload(): void
    {
        $info = new IndexInfo([
            'name' => 'email_1',
            'key' => ['email' => 1],
            'ns' => 'app.users',
            'v' => 2,
            'unique' => true,
            'sparse' => true,
            'expireAfterSeconds' => 7200,
        ]);

        $this->assertSame('email_1', $info->getName());
        $this->assertSame(['email' => 1], $info->getKey());
        $this->assertSame('app.users', $info->getNamespace());
        $this->assertSame(2, $info->getVersion());
        $this->assertTrue($info->isUnique());
        $this->assertTrue($info->isSparse());
        $this->assertTrue($info->isTtl());
    }

    public function testArrayAccessFromParent(): void
    {
        $info = new IndexInfo(['name' => 'idx', 'custom_field' => 'value']);
        $this->assertSame('idx', $info['name']);
        $this->assertSame('value', $info['custom_field']);
    }
}
