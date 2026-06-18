<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\Driver\Exception\InvalidArgumentException as DriverInvalidArgumentException;
use MongoDB\Exception\InvalidArgumentException as LibraryInvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Cursor;
use ZealPHP\MongoDB\ReadPreference;

/**
 * Parity: client-side argument validation. The official mongodb/mongodb +
 * ext-mongodb driver rejects these inputs BEFORE any wire I/O with typed
 * exceptions; the ZealPHP driver previously accepted/dropped them silently.
 * Each assertion is against the OFFICIAL exception type — the drop-in parity
 * contract. All cases here throw before any native zealphp_mongodb_* call, so
 * the suite needs no loaded extension. (GridFS #66 and the cursor
 * single-iteration contract #14 are covered by the Integration suite, which
 * exercises the loaded ext.)
 *
 * Issues: #50 (float limit/skip), #48 (empty-string key), #56 (empty
 * createIndexes), #68 (ReadPreference validation).
 */
class ClientSideValidationTest extends TestCase
{
    private function collection(): Collection
    {
        return new Collection(1, 'db', 'col');
    }

    // ── #50: float limit/skip ───────────────────────────────────────────
    public function testFindRejectsFloatLimit(): void
    {
        $this->expectException(LibraryInvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "limit" option to have type "integer" but found "float"');
        $this->collection()->find([], ['limit' => 5.7]);
    }

    public function testFindOneRejectsFloatSkip(): void
    {
        $this->expectException(LibraryInvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "skip" option to have type "integer" but found "float"');
        $this->collection()->findOne([], ['skip' => 3.9]);
    }

    public function testFindRejectsStringLimit(): void
    {
        $this->expectException(LibraryInvalidArgumentException::class);
        $this->collection()->find([], ['limit' => '5']);
    }

    public function testFindAcceptsIntegerLimitAndSkip(): void
    {
        $cursor = $this->collection()->find([], ['limit' => 5, 'skip' => 2]);
        $this->assertInstanceOf(Cursor::class, $cursor);
    }

    // ── #48: empty-string field key ─────────────────────────────────────
    public function testInsertOneRejectsEmptyStringKey(): void
    {
        $this->expectException(LibraryInvalidArgumentException::class);
        $this->expectExceptionMessage('Element key cannot be an empty string');
        $this->collection()->insertOne(['_id' => 1, '' => 'x']);
    }

    public function testInsertOneRejectsNestedEmptyStringKey(): void
    {
        $this->expectException(LibraryInvalidArgumentException::class);
        $this->collection()->insertOne(['_id' => 1, 'sub' => ['' => 'y']]);
    }

    // ── #56: empty createIndexes ────────────────────────────────────────
    public function testCreateIndexesRejectsEmptyArray(): void
    {
        $this->expectException(LibraryInvalidArgumentException::class);
        $this->expectExceptionMessage('$indexes is empty');
        $this->collection()->createIndexes([]);
    }

    // ── #68: ReadPreference validation ──────────────────────────────────
    public function testReadPreferenceRejectsInvalidMode(): void
    {
        $this->expectException(DriverInvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode');
        new ReadPreference('bogusmode');
    }

    public function testReadPreferenceRejectsEmptyMode(): void
    {
        $this->expectException(DriverInvalidArgumentException::class);
        new ReadPreference('');
    }

    public function testReadPreferenceRejectsPrimaryWithTagSets(): void
    {
        $this->expectException(DriverInvalidArgumentException::class);
        $this->expectExceptionMessage('tagSets may not be used with primary mode');
        new ReadPreference(ReadPreference::PRIMARY, [['dc' => 'east']]);
    }

    public function testReadPreferenceRejectsPrimaryWithMaxStaleness(): void
    {
        $this->expectException(DriverInvalidArgumentException::class);
        $this->expectExceptionMessage('maxStalenessSeconds may not be used with primary mode');
        new ReadPreference(ReadPreference::PRIMARY, null, ['maxStalenessSeconds' => 120]);
    }

    public function testReadPreferenceRejectsTooSmallMaxStaleness(): void
    {
        $this->expectException(DriverInvalidArgumentException::class);
        $this->expectExceptionMessage('Expected maxStalenessSeconds to be >= 90, 10 given');
        new ReadPreference(ReadPreference::SECONDARY, null, ['maxStalenessSeconds' => 10]);
    }

    public function testReadPreferenceAcceptsValidSecondary(): void
    {
        $rp = new ReadPreference(ReadPreference::SECONDARY, [['dc' => 'east']], ['maxStalenessSeconds' => 120]);
        $this->assertSame('secondary', $rp->getModeString());
        $this->assertSame(120, $rp->getMaxStalenessSeconds());
    }
}
