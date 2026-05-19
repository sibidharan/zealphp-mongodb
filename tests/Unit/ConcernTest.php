<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\WriteConcern;

class ConcernTest extends TestCase
{
    public function testReadConcernDefaults(): void
    {
        $rc = new ReadConcern();
        $this->assertNull($rc->getLevel());
        $this->assertTrue($rc->isDefault());
    }

    public function testReadConcernMajority(): void
    {
        $rc = new ReadConcern(ReadConcern::MAJORITY);
        $this->assertSame('majority', $rc->getLevel());
        $this->assertFalse($rc->isDefault());
    }

    public function testReadConcernConstants(): void
    {
        $this->assertSame('linearizable', ReadConcern::LINEARIZABLE);
        $this->assertSame('local', ReadConcern::LOCAL);
        $this->assertSame('majority', ReadConcern::MAJORITY);
        $this->assertSame('available', ReadConcern::AVAILABLE);
        $this->assertSame('snapshot', ReadConcern::SNAPSHOT);
    }

    public function testWriteConcern(): void
    {
        $wc = new WriteConcern(WriteConcern::MAJORITY, 5000, true);
        $this->assertSame('majority', $wc->getW());
        $this->assertSame(5000, $wc->getWtimeout());
        $this->assertTrue($wc->getJournal());
    }

    public function testWriteConcernNumeric(): void
    {
        $wc = new WriteConcern(1);
        $this->assertSame(1, $wc->getW());
        $this->assertSame(0, $wc->getWtimeout());
        $this->assertNull($wc->getJournal());
    }

    public function testReadPreferencePrimary(): void
    {
        $rp = new ReadPreference(ReadPreference::PRIMARY);
        $this->assertSame('primary', $rp->getModeString());
        $this->assertSame([], $rp->getTagSets());
    }

    public function testReadPreferenceConstants(): void
    {
        $this->assertSame('primary', ReadPreference::PRIMARY);
        $this->assertSame('primaryPreferred', ReadPreference::PRIMARY_PREFERRED);
        $this->assertSame('secondary', ReadPreference::SECONDARY);
        $this->assertSame('secondaryPreferred', ReadPreference::SECONDARY_PREFERRED);
        $this->assertSame('nearest', ReadPreference::NEAREST);
    }

    public function testReadPreferenceWithTags(): void
    {
        $tags = [['dc' => 'east']];
        $rp = new ReadPreference(ReadPreference::SECONDARY, $tags);
        $this->assertSame($tags, $rp->getTagSets());
    }
}
