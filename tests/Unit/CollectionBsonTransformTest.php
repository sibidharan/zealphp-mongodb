<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\BSON\Binary as OfficialBinary;
use MongoDB\BSON\Decimal128 as OfficialDecimal128;
use MongoDB\BSON\Javascript as OfficialJavascript;
use MongoDB\BSON\MaxKey as OfficialMaxKey;
use MongoDB\BSON\MinKey as OfficialMinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Timestamp as OfficialTimestamp;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;
use stdClass;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\Collection;

/**
 * Tests for Collection::wrapDoc() and Collection::prepareBSON().
 *
 * Since #45 these two methods use the OBJECT channel: a genuine BSON value is
 * carried PHP↔ext as a real MongoDB\BSON\* object, never as a {$oid:…} /
 * {$binary:…} extended-JSON array. That is what makes a *literal* user document
 * whose key happens to be `$oid`/`$binary`/… unambiguous from an actual BSON
 * value — the conflation these tests now guard against.
 *
 * - prepareBSON (write): BSON objects pass through (ZealPHP-namespace ones are
 *   normalized to their official MongoDB\BSON\* equivalent); a plain array is
 *   recursed and never reinterpreted by shape.
 * - wrapDoc (read): the ext already returns BSON objects, so wrapDoc passes
 *   objects through and NEVER reconstructs an extended-JSON array into a BSON
 *   type — a `['$oid' => …]` array is preserved as a BSONDocument.
 */
class CollectionBsonTransformTest extends TestCase
{
    // ─── wrapDoc: null and scalar passthrough ─────────────────────────

    public function testWrapDocNullReturnsNull(): void
    {
        $this->assertNull(Collection::wrapDoc(null));
    }

    public function testWrapDocStringPassesThrough(): void
    {
        $this->assertSame('hello', Collection::wrapDoc('hello'));
    }

    public function testWrapDocIntPassesThrough(): void
    {
        $this->assertSame(42, Collection::wrapDoc(42));
    }

    public function testWrapDocFloatPassesThrough(): void
    {
        $this->assertSame(3.14, Collection::wrapDoc(3.14));
    }

    public function testWrapDocBoolPassesThrough(): void
    {
        $this->assertTrue(Collection::wrapDoc(true));
    }

    // ─── wrapDoc: BSON objects (as the ext returns them) pass through ──

    public function testWrapDocObjectIdObjectPassesThrough(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');

        $this->assertSame($oid, Collection::wrapDoc($oid));
    }

    public function testWrapDocBinaryObjectPassesThrough(): void
    {
        $bin = new OfficialBinary('payload', OfficialBinary::TYPE_GENERIC);

        $this->assertSame($bin, Collection::wrapDoc($bin));
    }

    // ─── wrapDoc: #45 — extended-JSON arrays are NOT reconstructed ─────

    public function testWrapDocOidArrayStaysDocument(): void
    {
        $result = Collection::wrapDoc(['$oid' => '507f1f77bcf86cd799439011']);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertSame('507f1f77bcf86cd799439011', $result['$oid']);
    }

    public function testWrapDocBinaryArrayStaysDocument(): void
    {
        $result = Collection::wrapDoc([
            '$binary' => ['base64' => 'AQID', 'subType' => '00'],
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONDocument::class, $result['$binary']);
    }

    public function testWrapDocNumberDecimalArrayStaysDocument(): void
    {
        $result = Collection::wrapDoc(['$numberDecimal' => '123.456']);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertSame('123.456', $result['$numberDecimal']);
    }

    public function testWrapDocNestedOidArrayStaysDocument(): void
    {
        $result = Collection::wrapDoc([
            'wrapper' => ['$oid' => '507f1f77bcf86cd799439011'],
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONDocument::class, $result['wrapper']);
        $this->assertSame('507f1f77bcf86cd799439011', $result['wrapper']['$oid']);
    }

    // ─── wrapDoc: sequential array → BSONArray ────────────────────────

    public function testWrapDocSequentialArrayBecomesBsonArray(): void
    {
        $result = Collection::wrapDoc([1, 2, 3]);

        $this->assertInstanceOf(BSONArray::class, $result);
        $this->assertSame([1, 2, 3], $result->getArrayCopy());
    }

    public function testWrapDocEmptyListBecomesBsonArray(): void
    {
        $result = Collection::wrapDoc([]);

        $this->assertInstanceOf(BSONArray::class, $result);
        $this->assertCount(0, $result);
    }

    public function testWrapDocListWithObjectIdObjectPreservesIt(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $result = Collection::wrapDoc([$oid, 'plain string']);

        $this->assertInstanceOf(BSONArray::class, $result);
        $copy = $result->getArrayCopy();
        $this->assertSame($oid, $copy[0]);
        $this->assertSame('plain string', $copy[1]);
    }

    // ─── wrapDoc: associative array → BSONDocument ────────────────────

    public function testWrapDocAssociativeArrayBecomesBsonDocument(): void
    {
        $result = Collection::wrapDoc(['name' => 'Alice', 'age' => 30]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertSame('Alice', $result['name']);
        $this->assertSame(30, $result['age']);
    }

    public function testWrapDocDocumentRecursivelyWrapsValues(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $result = Collection::wrapDoc([
            'user' => ['name' => 'Bob'],
            'id'   => $oid,
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONDocument::class, $result['user']);
        $this->assertSame($oid, $result['id']);
    }

    public function testWrapDocDeeplyNestedDocument(): void
    {
        $oid = new ObjectId('aabbccddeeff00112233aabb');
        $result = Collection::wrapDoc([
            'level1' => ['level2' => ['level3' => $oid]],
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONDocument::class, $result['level1']);
        $this->assertSame($oid, $result['level1']['level2']['level3']);
    }

    public function testWrapDocDocumentContainingList(): void
    {
        $result = Collection::wrapDoc([
            'tags' => ['php', 'mongodb', 'async'],
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONArray::class, $result['tags']);
    }

    public function testWrapDocListContainingDocuments(): void
    {
        $result = Collection::wrapDoc([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $this->assertInstanceOf(BSONArray::class, $result);
        $items = $result->getArrayCopy();
        $this->assertInstanceOf(BSONDocument::class, $items[0]);
        $this->assertInstanceOf(BSONDocument::class, $items[1]);
    }

    public function testWrapDocMixedTypesInDocument(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $date = new UTCDateTime(1716000000000);
        $result = Collection::wrapDoc([
            '_id'     => $oid,
            'created' => $date,
            'tags'    => ['a', 'b'],
            'active'  => true,
            'name'    => 'Test',
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertSame($oid, $result['_id']);
        $this->assertSame($date, $result['created']);
        $this->assertInstanceOf(BSONArray::class, $result['tags']);
        $this->assertTrue($result['active']);
        $this->assertSame('Test', $result['name']);
    }

    // ─── wrapDoc: scalar values inside documents pass through ─────────

    public function testWrapDocScalarValuesInsideDocumentPreserved(): void
    {
        $result = Collection::wrapDoc([
            'int'    => 42,
            'float'  => 3.14,
            'string' => 'hello',
            'bool'   => false,
            'null'   => null,
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertSame(42, $result['int']);
        $this->assertSame(3.14, $result['float']);
        $this->assertSame('hello', $result['string']);
        $this->assertFalse($result['bool']);
        $this->assertNull($result['null']);
    }

    // ─── prepareBSON: official BSON objects pass through unchanged ─────

    public function testPrepareBsonObjectId(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');

        $this->assertSame($oid, Collection::prepareBSON($oid));
    }

    public function testPrepareBsonUtcDateTime(): void
    {
        $dt = new UTCDateTime(1716000000000);

        $this->assertSame($dt, Collection::prepareBSON($dt));
    }

    public function testPrepareBsonRegex(): void
    {
        $regex = new Regex('^hello', 'im');

        $this->assertSame($regex, Collection::prepareBSON($regex));
    }

    public function testPrepareBsonOfficialBinaryPassesThrough(): void
    {
        $bin = new OfficialBinary('payload', OfficialBinary::TYPE_GENERIC);

        $this->assertSame($bin, Collection::prepareBSON($bin));
    }

    // ─── prepareBSON: ZealPHP BSON objects → official equivalents ──────

    public function testPrepareBsonBinary(): void
    {
        $bin = new Binary('payload', Binary::TYPE_GENERIC);
        $result = Collection::prepareBSON($bin);

        $this->assertInstanceOf(OfficialBinary::class, $result);
        $this->assertSame('payload', $result->getData());
        $this->assertSame(OfficialBinary::TYPE_GENERIC, $result->getType());
    }

    public function testPrepareBsonDecimal128(): void
    {
        $dec = new Decimal128('1234.5678');
        $result = Collection::prepareBSON($dec);

        $this->assertInstanceOf(OfficialDecimal128::class, $result);
        $this->assertSame('1234.5678', (string) $result);
    }

    public function testPrepareBsonTimestamp(): void
    {
        $ts = new Timestamp(5, 1234567890);
        $result = Collection::prepareBSON($ts);

        $this->assertInstanceOf(OfficialTimestamp::class, $result);
        $this->assertSame(1234567890, $result->getTimestamp());
        $this->assertSame(5, $result->getIncrement());
    }

    public function testPrepareBsonJavascript(): void
    {
        $js = new Javascript('return 1;');
        $result = Collection::prepareBSON($js);

        $this->assertInstanceOf(OfficialJavascript::class, $result);
        $this->assertSame('return 1;', $result->getCode());
        $this->assertNull($result->getScope());
    }

    public function testPrepareBsonJavascriptWithScope(): void
    {
        $js = new Javascript('return x;', (object) ['x' => 10]);
        $result = Collection::prepareBSON($js);

        $this->assertInstanceOf(OfficialJavascript::class, $result);
        $this->assertSame('return x;', $result->getCode());
        $this->assertNotNull($result->getScope());
    }

    public function testPrepareBsonMinKey(): void
    {
        $this->assertInstanceOf(OfficialMinKey::class, Collection::prepareBSON(new MinKey()));
    }

    public function testPrepareBsonMaxKey(): void
    {
        $this->assertInstanceOf(OfficialMaxKey::class, Collection::prepareBSON(new MaxKey()));
    }

    // ─── prepareBSON: Int64 ───────────────────────────────────────────

    public function testPrepareBsonInt64CastsToInt(): void
    {
        $i64 = new Int64(9876543210);
        $result = Collection::prepareBSON($i64);

        $this->assertIsInt($result);
        $this->assertSame(9876543210, $result);
    }

    // ─── prepareBSON: #45 — literal sentinel-shaped arrays stay arrays ─

    public function testPrepareBsonLiteralOidArrayStaysArray(): void
    {
        $result = Collection::prepareBSON(['$oid' => '507f1f77bcf86cd799439011']);

        $this->assertIsArray($result);
        $this->assertSame('507f1f77bcf86cd799439011', $result['$oid']);
    }

    public function testPrepareBsonLiteralBinaryArrayStaysArray(): void
    {
        $result = Collection::prepareBSON(['$binary' => ['base64' => 'AQID', 'subType' => '00']]);

        $this->assertIsArray($result);
        $this->assertSame('AQID', $result['$binary']['base64']);
    }

    // ─── prepareBSON: BSONDocument / BSONArray ────────────────────────

    public function testPrepareBsonDocument(): void
    {
        $doc = new BSONDocument(['name' => 'Alice', 'age' => 30]);
        $result = Collection::prepareBSON($doc);

        $this->assertIsArray($result);
        $this->assertSame('Alice', $result['name']);
        $this->assertSame(30, $result['age']);
    }

    public function testPrepareBsonDocumentRecursive(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $inner = new BSONDocument(['id' => $oid]);
        $outer = new BSONDocument(['nested' => $inner]);
        $result = Collection::prepareBSON($outer);

        $this->assertIsArray($result);
        $this->assertIsArray($result['nested']);
        $this->assertSame($oid, $result['nested']['id']);
    }

    public function testPrepareBsonArray(): void
    {
        $arr = new BSONArray([1, 2, 3]);
        $result = Collection::prepareBSON($arr);

        $this->assertIsArray($result);
        $this->assertSame([1, 2, 3], $result);
    }

    public function testPrepareBsonArrayWithTypedElements(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $arr = new BSONArray([$oid, new MinKey()]);
        $result = Collection::prepareBSON($arr);

        $this->assertIsArray($result);
        $this->assertSame($oid, $result[0]);
        $this->assertInstanceOf(OfficialMinKey::class, $result[1]);
    }

    // ─── prepareBSON: plain array (recursive) ─────────────────────────

    public function testPrepareBsonPlainArrayRecursive(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $dt = new UTCDateTime(1000);
        $result = Collection::prepareBSON([
            'id'   => $oid,
            'time' => $dt,
            'val'  => 'plain',
        ]);

        $this->assertIsArray($result);
        $this->assertSame($oid, $result['id']);
        $this->assertSame($dt, $result['time']);
        $this->assertSame('plain', $result['val']);
    }

    // ─── prepareBSON: stdClass (recursive) ────────────────────────────

    public function testPrepareBsonStdClassRecursive(): void
    {
        $oid = new ObjectId('aabbccddeeff00112233aabb');
        $obj = new stdClass();
        $obj->id = $oid;
        $obj->name = 'Test';
        $obj->score = 42;

        $result = Collection::prepareBSON($obj);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame($oid, $result->id);
        $this->assertSame('Test', $result->name);
        $this->assertSame(42, $result->score);
    }

    // ─── prepareBSON: scalar passthrough ──────────────────────────────

    public function testPrepareBsonScalarString(): void
    {
        $this->assertSame('hello', Collection::prepareBSON('hello'));
    }

    public function testPrepareBsonScalarInt(): void
    {
        $this->assertSame(42, Collection::prepareBSON(42));
    }

    public function testPrepareBsonScalarNull(): void
    {
        $this->assertNull(Collection::prepareBSON(null));
    }

    public function testPrepareBsonScalarBool(): void
    {
        $this->assertFalse(Collection::prepareBSON(false));
    }

    // ─── Round-trip: wrapDoc(prepareBSON(X)) preserves the value ──────

    public function testRoundTripObjectId(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $result = Collection::wrapDoc(Collection::prepareBSON($oid));

        $this->assertInstanceOf(ObjectId::class, $result);
        $this->assertSame((string) $oid, (string) $result);
    }

    public function testRoundTripUtcDateTime(): void
    {
        $dt = new UTCDateTime(1716000000000);
        $result = Collection::wrapDoc(Collection::prepareBSON($dt));

        $this->assertInstanceOf(UTCDateTime::class, $result);
        $this->assertSame((string) $dt, (string) $result);
    }

    public function testRoundTripDecimal128(): void
    {
        $dec = new Decimal128('99.99');
        $result = Collection::wrapDoc(Collection::prepareBSON($dec));

        $this->assertInstanceOf(OfficialDecimal128::class, $result);
        $this->assertSame('99.99', (string) $result);
    }

    public function testRoundTripMinKey(): void
    {
        $result = Collection::wrapDoc(Collection::prepareBSON(new MinKey()));
        $this->assertInstanceOf(OfficialMinKey::class, $result);
    }

    public function testRoundTripMaxKey(): void
    {
        $result = Collection::wrapDoc(Collection::prepareBSON(new MaxKey()));
        $this->assertInstanceOf(OfficialMaxKey::class, $result);
    }

    public function testRoundTripTimestamp(): void
    {
        $ts = new Timestamp(3, 1000000);
        $result = Collection::wrapDoc(Collection::prepareBSON($ts));

        $this->assertInstanceOf(OfficialTimestamp::class, $result);
        $this->assertSame(1000000, $result->getTimestamp());
        $this->assertSame(3, $result->getIncrement());
    }

    public function testRoundTripBinary(): void
    {
        $bin = new Binary('round-trip-data', Binary::TYPE_GENERIC);
        $result = Collection::wrapDoc(Collection::prepareBSON($bin));

        $this->assertInstanceOf(OfficialBinary::class, $result);
        $this->assertSame('round-trip-data', $result->getData());
    }

    // ─── Edge cases ───────────────────────────────────────────────────

    public function testWrapDocEmptyAssociativeArrayBecomesEmptyBsonArray(): void
    {
        $result = Collection::wrapDoc([]);
        $this->assertInstanceOf(BSONArray::class, $result);
        $this->assertCount(0, $result);
    }

    public function testPrepareBsonEmptyBsonDocument(): void
    {
        $doc = new BSONDocument([]);
        $result = Collection::prepareBSON($doc);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testPrepareBsonEmptyBsonArray(): void
    {
        $arr = new BSONArray([]);
        $result = Collection::prepareBSON($arr);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testPrepareBsonEmptyPlainArray(): void
    {
        $result = Collection::prepareBSON([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
