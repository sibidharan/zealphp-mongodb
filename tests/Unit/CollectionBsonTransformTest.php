<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
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

use function base64_encode;

/**
 * Tests for Collection::wrapDoc() and Collection::prepareBSON().
 *
 * These two static methods handle conversion between raw extended-JSON arrays
 * (as returned by the Rust FFI layer) and rich BSON type objects.
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

    // ─── wrapDoc: ObjectId ────────────────────────────────────────────

    public function testWrapDocObjectId(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        $result = Collection::wrapDoc(['$oid' => $hex]);

        $this->assertInstanceOf(ObjectId::class, $result);
        $this->assertSame($hex, (string) $result);
    }

    // ─── wrapDoc: UTCDateTime ─────────────────────────────────────────

    public function testWrapDocUtcDateTimeWithNumberLong(): void
    {
        $ms = '1716000000000';
        $result = Collection::wrapDoc(['$date' => ['$numberLong' => $ms]]);

        $this->assertInstanceOf(UTCDateTime::class, $result);
        $this->assertSame($ms, (string) $result);
    }

    public function testWrapDocUtcDateTimeWithPlainInt(): void
    {
        $ms = 1716000000000;
        $result = Collection::wrapDoc(['$date' => $ms]);

        $this->assertInstanceOf(UTCDateTime::class, $result);
        $this->assertSame((string) $ms, (string) $result);
    }

    // ─── wrapDoc: Decimal128 ──────────────────────────────────────────

    public function testWrapDocDecimal128(): void
    {
        $result = Collection::wrapDoc(['$numberDecimal' => '123.456']);

        $this->assertInstanceOf(Decimal128::class, $result);
        $this->assertSame('123.456', (string) $result);
    }

    // ─── wrapDoc: Binary ──────────────────────────────────────────────

    public function testWrapDocBinary(): void
    {
        $rawData = 'binary payload';
        $result = Collection::wrapDoc([
            '$binary' => [
                'base64'  => base64_encode($rawData),
                'subType' => '00',
            ],
        ]);

        $this->assertInstanceOf(Binary::class, $result);
        $this->assertSame($rawData, $result->getData());
        $this->assertSame(Binary::TYPE_GENERIC, $result->getType());
    }

    public function testWrapDocBinaryUuidSubtype(): void
    {
        $rawData = 'uuid-binary-data';
        $result = Collection::wrapDoc([
            '$binary' => [
                'base64'  => base64_encode($rawData),
                'subType' => '04',
            ],
        ]);

        $this->assertInstanceOf(Binary::class, $result);
        $this->assertSame(Binary::TYPE_UUID, $result->getType());
    }

    // ─── wrapDoc: Regex ───────────────────────────────────────────────

    public function testWrapDocRegex(): void
    {
        $result = Collection::wrapDoc([
            '$regularExpression' => [
                'pattern' => '^test',
                'options' => 'i',
            ],
        ]);

        $this->assertInstanceOf(Regex::class, $result);
        $this->assertSame('^test', $result->getPattern());
        $this->assertSame('i', $result->getFlags());
    }

    public function testWrapDocRegexEmptyOptions(): void
    {
        $result = Collection::wrapDoc([
            '$regularExpression' => [
                'pattern' => '.*',
                'options' => '',
            ],
        ]);

        $this->assertInstanceOf(Regex::class, $result);
        $this->assertSame('', $result->getFlags());
    }

    // ─── wrapDoc: Timestamp ───────────────────────────────────────────

    public function testWrapDocTimestamp(): void
    {
        $result = Collection::wrapDoc([
            '$timestamp' => ['t' => 1234567890, 'i' => 1],
        ]);

        $this->assertInstanceOf(Timestamp::class, $result);
        $this->assertSame(1234567890, $result->getTimestamp());
        $this->assertSame(1, $result->getIncrement());
    }

    // ─── wrapDoc: Javascript ──────────────────────────────────────────

    public function testWrapDocJavascriptWithoutScope(): void
    {
        $result = Collection::wrapDoc(['$code' => 'return true;']);

        $this->assertInstanceOf(Javascript::class, $result);
        $this->assertSame('return true;', $result->getCode());
        $this->assertNull($result->getScope());
    }

    public function testWrapDocJavascriptWithScope(): void
    {
        $result = Collection::wrapDoc([
            '$code'  => 'return x;',
            '$scope' => ['x' => 42],
        ]);

        $this->assertInstanceOf(Javascript::class, $result);
        $this->assertSame('return x;', $result->getCode());
        $this->assertNotNull($result->getScope());
    }

    // ─── wrapDoc: MinKey / MaxKey ─────────────────────────────────────

    public function testWrapDocMinKey(): void
    {
        $result = Collection::wrapDoc(['$minKey' => 1]);

        $this->assertInstanceOf(MinKey::class, $result);
    }

    public function testWrapDocMaxKey(): void
    {
        $result = Collection::wrapDoc(['$maxKey' => 1]);

        $this->assertInstanceOf(MaxKey::class, $result);
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

    public function testWrapDocNestedArrayInList(): void
    {
        $result = Collection::wrapDoc([
            ['$oid' => '507f1f77bcf86cd799439011'],
            'plain string',
        ]);

        $this->assertInstanceOf(BSONArray::class, $result);
        $copy = $result->getArrayCopy();
        $this->assertInstanceOf(ObjectId::class, $copy[0]);
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
        $result = Collection::wrapDoc([
            'user' => ['name' => 'Bob'],
            'id'   => ['$oid' => '507f1f77bcf86cd799439011'],
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONDocument::class, $result['user']);
        $this->assertInstanceOf(ObjectId::class, $result['id']);
    }

    // ─── wrapDoc: nested / mixed structures ───────────────────────────

    public function testWrapDocDeeplyNestedDocument(): void
    {
        $result = Collection::wrapDoc([
            'level1' => [
                'level2' => [
                    'level3' => ['$oid' => 'aabbccddeeff00112233aabb'],
                ],
            ],
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(BSONDocument::class, $result['level1']);
        $this->assertInstanceOf(ObjectId::class, $result['level1']['level2']['level3']);
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
        $result = Collection::wrapDoc([
            '_id'       => ['$oid' => '507f1f77bcf86cd799439011'],
            'created'   => ['$date' => ['$numberLong' => '1716000000000']],
            'score'     => ['$numberDecimal' => '99.99'],
            'tags'      => ['a', 'b'],
            'active'    => true,
            'name'      => 'Test',
        ]);

        $this->assertInstanceOf(BSONDocument::class, $result);
        $this->assertInstanceOf(ObjectId::class, $result['_id']);
        $this->assertInstanceOf(UTCDateTime::class, $result['created']);
        $this->assertInstanceOf(Decimal128::class, $result['score']);
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

    // ─── prepareBSON: ObjectId ────────────────────────────────────────

    public function testPrepareBsonObjectId(): void
    {
        $oid = new ObjectId('507f1f77bcf86cd799439011');
        $result = Collection::prepareBSON($oid);

        $this->assertIsArray($result);
        $this->assertSame('507f1f77bcf86cd799439011', $result['$oid']);
    }

    // ─── prepareBSON: UTCDateTime ─────────────────────────────────────

    public function testPrepareBsonUtcDateTime(): void
    {
        $dt = new UTCDateTime(1716000000000);
        $result = Collection::prepareBSON($dt);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('$date', $result);
        $this->assertSame('1716000000000', $result['$date']['$numberLong']);
    }

    // ─── prepareBSON: Regex ───────────────────────────────────────────

    public function testPrepareBsonRegex(): void
    {
        $regex = new Regex('^hello', 'im');
        $result = Collection::prepareBSON($regex);

        $this->assertIsArray($result);
        $this->assertSame('^hello', $result['$regularExpression']['pattern']);
        $this->assertSame('im', $result['$regularExpression']['options']);
    }

    // ─── prepareBSON: Binary ──────────────────────────────────────────

    public function testPrepareBsonBinary(): void
    {
        $bin = new Binary('payload', Binary::TYPE_GENERIC);
        $result = Collection::prepareBSON($bin);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('$binary', $result);
        $this->assertSame(base64_encode('payload'), $result['$binary']['base64']);
        $this->assertSame('00', $result['$binary']['subType']);
    }

    // ─── prepareBSON: Decimal128 ──────────────────────────────────────

    public function testPrepareBsonDecimal128(): void
    {
        $dec = new Decimal128('1234.5678');
        $result = Collection::prepareBSON($dec);

        $this->assertIsArray($result);
        $this->assertSame('1234.5678', $result['$numberDecimal']);
    }

    // ─── prepareBSON: Timestamp ───────────────────────────────────────

    public function testPrepareBsonTimestamp(): void
    {
        $ts = new Timestamp(5, 1234567890);
        $result = Collection::prepareBSON($ts);

        $this->assertIsArray($result);
        $this->assertSame(1234567890, $result['$timestamp']['t']);
        $this->assertSame(5, $result['$timestamp']['i']);
    }

    // ─── prepareBSON: Javascript ──────────────────────────────────────

    public function testPrepareBsonJavascript(): void
    {
        $js = new Javascript('return 1;');
        $result = Collection::prepareBSON($js);

        $this->assertIsArray($result);
        $this->assertSame('return 1;', $result['$code']);
        $this->assertArrayNotHasKey('$scope', $result);
    }

    public function testPrepareBsonJavascriptWithScope(): void
    {
        $js = new Javascript('return x;', ['x' => 10]);
        $result = Collection::prepareBSON($js);

        $this->assertIsArray($result);
        $this->assertSame('return x;', $result['$code']);
        $this->assertArrayHasKey('$scope', $result);
    }

    // ─── prepareBSON: MinKey / MaxKey ─────────────────────────────────

    public function testPrepareBsonMinKey(): void
    {
        $result = Collection::prepareBSON(new MinKey());

        $this->assertIsArray($result);
        $this->assertSame(1, $result['$minKey']);
    }

    public function testPrepareBsonMaxKey(): void
    {
        $result = Collection::prepareBSON(new MaxKey());

        $this->assertIsArray($result);
        $this->assertSame(1, $result['$maxKey']);
    }

    // ─── prepareBSON: Int64 ───────────────────────────────────────────

    public function testPrepareBsonInt64CastsToInt(): void
    {
        $i64 = new Int64(9876543210);
        $result = Collection::prepareBSON($i64);

        $this->assertIsInt($result);
        $this->assertSame(9876543210, $result);
    }

    // ─── prepareBSON: BSONDocument ────────────────────────────────────

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
        $inner = new BSONDocument(['id' => new ObjectId('507f1f77bcf86cd799439011')]);
        $outer = new BSONDocument(['nested' => $inner]);
        $result = Collection::prepareBSON($outer);

        $this->assertIsArray($result);
        $this->assertIsArray($result['nested']);
        $this->assertSame('507f1f77bcf86cd799439011', $result['nested']['id']['$oid']);
    }

    // ─── prepareBSON: BSONArray ───────────────────────────────────────

    public function testPrepareBsonArray(): void
    {
        $arr = new BSONArray([1, 2, 3]);
        $result = Collection::prepareBSON($arr);

        $this->assertIsArray($result);
        $this->assertSame([1, 2, 3], $result);
    }

    public function testPrepareBsonArrayWithTypedElements(): void
    {
        $arr = new BSONArray([
            new ObjectId('507f1f77bcf86cd799439011'),
            new MinKey(),
        ]);
        $result = Collection::prepareBSON($arr);

        $this->assertIsArray($result);
        $this->assertSame('507f1f77bcf86cd799439011', $result[0]['$oid']);
        $this->assertSame(1, $result[1]['$minKey']);
    }

    // ─── prepareBSON: plain array (recursive) ─────────────────────────

    public function testPrepareBsonPlainArrayRecursive(): void
    {
        $data = [
            'id'   => new ObjectId('507f1f77bcf86cd799439011'),
            'time' => new UTCDateTime(1000),
            'val'  => 'plain',
        ];
        $result = Collection::prepareBSON($data);

        $this->assertIsArray($result);
        $this->assertSame('507f1f77bcf86cd799439011', $result['id']['$oid']);
        $this->assertSame('1000', $result['time']['$date']['$numberLong']);
        $this->assertSame('plain', $result['val']);
    }

    // ─── prepareBSON: stdClass (recursive) ────────────────────────────

    public function testPrepareBsonStdClassRecursive(): void
    {
        $obj = new stdClass();
        $obj->id = new ObjectId('aabbccddeeff00112233aabb');
        $obj->name = 'Test';
        $obj->score = 42;

        $result = Collection::prepareBSON($obj);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertIsArray($result->id);
        $this->assertSame('aabbccddeeff00112233aabb', $result->id['$oid']);
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

    // ─── Round-trip: wrapDoc(prepareBSON(X)) ──────────────────────────

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

        $this->assertInstanceOf(Decimal128::class, $result);
        $this->assertSame('99.99', (string) $result);
    }

    public function testRoundTripMinKey(): void
    {
        $result = Collection::wrapDoc(Collection::prepareBSON(new MinKey()));
        $this->assertInstanceOf(MinKey::class, $result);
    }

    public function testRoundTripMaxKey(): void
    {
        $result = Collection::wrapDoc(Collection::prepareBSON(new MaxKey()));
        $this->assertInstanceOf(MaxKey::class, $result);
    }

    public function testRoundTripTimestamp(): void
    {
        $ts = new Timestamp(3, 1000000);
        $result = Collection::wrapDoc(Collection::prepareBSON($ts));

        $this->assertInstanceOf(Timestamp::class, $result);
        $this->assertSame(1000000, $result->getTimestamp());
        $this->assertSame(3, $result->getIncrement());
    }

    public function testRoundTripBinary(): void
    {
        $bin = new Binary('round-trip-data', Binary::TYPE_GENERIC);
        $result = Collection::wrapDoc(Collection::prepareBSON($bin));

        $this->assertInstanceOf(Binary::class, $result);
        $this->assertSame('round-trip-data', $result->getData());
    }

    // ─── Edge cases ───────────────────────────────────────────────────

    public function testWrapDocEmptyAssociativeArrayBecomesEmptyBsonArray(): void
    {
        // An empty array passes array_is_list() → BSONArray
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
