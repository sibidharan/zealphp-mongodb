<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\WriteConcern;

class ConcernSerializationTest extends TestCase
{
    // ── ReadConcern serialization ────────────────────────────────────

    public function testReadConcernJsonSerializeWithLevel(): void
    {
        $rc = new ReadConcern(ReadConcern::MAJORITY);
        $result = $rc->jsonSerialize();

        $this->assertIsArray($result);
        $this->assertSame(['level' => 'majority'], $result);
    }

    public function testReadConcernJsonSerializeWithoutLevel(): void
    {
        $rc = new ReadConcern();
        $result = $rc->jsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function testReadConcernJsonSerializeLocalLevel(): void
    {
        $rc = new ReadConcern(ReadConcern::LOCAL);
        $result = $rc->jsonSerialize();

        $this->assertSame(['level' => 'local'], $result);
    }

    public function testReadConcernBsonSerializeWithLevel(): void
    {
        $rc = new ReadConcern(ReadConcern::LINEARIZABLE);
        $result = $rc->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame('linearizable', $result->level);
    }

    public function testReadConcernBsonSerializeWithoutLevel(): void
    {
        $rc = new ReadConcern();
        $result = $rc->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertObjectNotHasProperty('level', $result);
    }

    public function testReadConcernBsonSerializeSnapshotLevel(): void
    {
        $rc = new ReadConcern(ReadConcern::SNAPSHOT);
        $result = $rc->bsonSerialize();

        $this->assertSame('snapshot', $result->level);
    }

    // ── WriteConcern serialization ──────────────────────────────────

    public function testWriteConcernIsDefaultTrue(): void
    {
        $wc = new WriteConcern(null);
        $this->assertTrue($wc->isDefault());
    }

    public function testWriteConcernIsDefaultFalseWhenWSet(): void
    {
        $wc = new WriteConcern(1);
        $this->assertFalse($wc->isDefault());
    }

    public function testWriteConcernIsDefaultFalseWhenJSet(): void
    {
        $wc = new WriteConcern(null, null, true);
        $this->assertFalse($wc->isDefault());
    }

    public function testWriteConcernIsDefaultFalseWhenWtimeoutSet(): void
    {
        $wc = new WriteConcern(null, 5000);
        $this->assertFalse($wc->isDefault());
    }

    public function testWriteConcernIsDefaultFalseWithMajority(): void
    {
        $wc = new WriteConcern(WriteConcern::MAJORITY);
        $this->assertFalse($wc->isDefault());
    }

    public function testWriteConcernJsonSerialize(): void
    {
        $wc = new WriteConcern(WriteConcern::MAJORITY, 5000, true);
        $result = $wc->jsonSerialize();

        $this->assertSame(['w' => 'majority', 'j' => true, 'wtimeout' => 5000], $result);
    }

    public function testWriteConcernJsonSerializeNullValues(): void
    {
        $wc = new WriteConcern(null);
        $result = $wc->jsonSerialize();

        $this->assertSame(['w' => null, 'j' => null, 'wtimeout' => 0], $result);
    }

    public function testWriteConcernBsonSerializeFiltersNulls(): void
    {
        $wc = new WriteConcern(null);
        $result = $wc->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertObjectNotHasProperty('w', $result);
        $this->assertObjectNotHasProperty('j', $result);
        // wtimeout is 0 (not null) so it should be present
        $this->assertObjectHasProperty('wtimeout', $result);
        $this->assertSame(0, $result->wtimeout);
    }

    public function testWriteConcernBsonSerializeAllValues(): void
    {
        $wc = new WriteConcern(2, 3000, false);
        $result = $wc->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame(2, $result->w);
        $this->assertFalse($result->j);
        $this->assertSame(3000, $result->wtimeout);
    }

    public function testWriteConcernMajorityConstant(): void
    {
        $this->assertSame('majority', WriteConcern::MAJORITY);
    }

    // ── ReadPreference serialization ────────────────────────────────

    public function testReadPreferenceMaxStalenessSecondsDefault(): void
    {
        $rp = new ReadPreference(ReadPreference::PRIMARY);
        $this->assertSame(-1, $rp->getMaxStalenessSeconds());
        $this->assertSame(ReadPreference::NO_MAX_STALENESS, $rp->getMaxStalenessSeconds());
    }

    public function testReadPreferenceMaxStalenessSecondsCustom(): void
    {
        $rp = new ReadPreference(ReadPreference::SECONDARY, null, ['maxStalenessSeconds' => 120]);
        $this->assertSame(120, $rp->getMaxStalenessSeconds());
    }

    public function testReadPreferenceJsonSerialize(): void
    {
        $rp = new ReadPreference(ReadPreference::NEAREST);
        $result = $rp->jsonSerialize();

        $this->assertSame(['mode' => 'nearest'], $result);
    }

    public function testReadPreferenceBsonSerialize(): void
    {
        $rp = new ReadPreference(ReadPreference::SECONDARY_PREFERRED);
        $result = $rp->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame('secondaryPreferred', $result->mode);
    }

    public function testReadPreferenceConstructorWithOptions(): void
    {
        $tags = [['dc' => 'west']];
        $rp = new ReadPreference(ReadPreference::SECONDARY, $tags, ['maxStalenessSeconds' => 200]);

        $this->assertSame('secondary', $rp->getModeString());
        $this->assertSame($tags, $rp->getTagSets());
        $this->assertSame(200, $rp->getMaxStalenessSeconds());
    }

    public function testReadPreferenceSmallestMaxStalenessConstant(): void
    {
        $this->assertSame(90, ReadPreference::SMALLEST_MAX_STALENESS_SECONDS);
    }

    public function testReadPreferenceNoMaxStalenessConstant(): void
    {
        $this->assertSame(-1, ReadPreference::NO_MAX_STALENESS);
    }
}
