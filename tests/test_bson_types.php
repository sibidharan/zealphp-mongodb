<?php

declare(strict_types=1);

/**
 * BSON Type System Tests
 *
 * Tests all BSON interfaces and type classes for the ZealPHP MongoDB driver.
 * Uses a simple pass/fail counter -- no external test framework required.
 */

// Autoloader for ZealPHP\MongoDB\BSON namespace
spl_autoload_register(static function (string $class): void {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (! file_exists($file)) {
        return;
    }

    require_once $file;
});

use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\BinaryInterface;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Decimal128Interface;
use ZealPHP\MongoDB\BSON\Document;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\JavascriptInterface;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\ObjectId;
use ZealPHP\MongoDB\BSON\ObjectIdInterface;
use ZealPHP\MongoDB\BSON\PackedArray;
use ZealPHP\MongoDB\BSON\Persistable;
use ZealPHP\MongoDB\BSON\Regex;
use ZealPHP\MongoDB\BSON\RegexInterface;
use ZealPHP\MongoDB\BSON\Serializable;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\BSON\TimestampInterface;
use ZealPHP\MongoDB\BSON\Type;
use ZealPHP\MongoDB\BSON\Unserializable;
use ZealPHP\MongoDB\BSON\UTCDateTime;
use ZealPHP\MongoDB\BSON\UTCDateTimeInterface;

$passed = 0;
$failed = 0;
$errors = [];

function assert_true(string $label, bool $condition): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        $errors[] = $label;
        echo "  FAIL: $label\n";
    }
}

function assert_equals(string $label, mixed $expected, mixed $actual): void
{
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        $errors[] = $label;
        echo "  FAIL: $label\n";
        echo '    expected: ' . var_export($expected, true) . "\n";
        echo '    actual:   ' . var_export($actual, true) . "\n";
    }
}

function assert_throws(string $label, string $exceptionClass, callable $fn): void
{
    global $passed, $failed, $errors;
    try {
        $fn();
        $failed++;
        $errors[] = $label;
        echo "  FAIL: $label (no exception thrown)\n";
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            $passed++;
        } else {
            $failed++;
            $errors[] = $label;
            echo "  FAIL: $label (got " . $e::class . ': ' . $e->getMessage() . ")\n";
        }
    }
}

// ============================================================
echo "=== BSON Interfaces ===\n";
// ============================================================

echo "\n-- Interface hierarchy --\n";
assert_true(
    'Serializable extends Type',
    is_subclass_of(Serializable::class, Type::class, true),
);
assert_true(
    'Persistable extends Serializable',
    is_subclass_of(Persistable::class, Serializable::class, true),
);
assert_true(
    'Persistable extends Unserializable',
    is_subclass_of(Persistable::class, Unserializable::class, true),
);

// ============================================================
echo "\n=== ObjectId ===\n";
// ============================================================

echo "\n-- Construction --\n";
$oid = new ObjectId();
assert_equals('Generated ObjectId is 24 hex chars', 24, strlen((string) $oid));
assert_true('Generated ObjectId is valid hex', ctype_xdigit((string) $oid));

$oid2 = new ObjectId('507f1f77bcf86cd799439011');
assert_equals('ObjectId from string', '507f1f77bcf86cd799439011', (string) $oid2);

$oid3 = new ObjectId('507F1F77BCF86CD799439011');
assert_equals('ObjectId normalizes to lowercase', '507f1f77bcf86cd799439011', (string) $oid3);

echo "\n-- ObjectId from DateTimeInterface --\n";
$dt = new DateTime('2023-01-01T00:00:00Z');
$oidFromDate = new ObjectId($dt);
$ts = $oidFromDate->getTimestamp();
assert_equals('ObjectId from DateTime has correct timestamp', $dt->getTimestamp(), $ts);
assert_equals('ObjectId from DateTime is 24 chars', 24, strlen((string) $oidFromDate));

echo "\n-- ObjectId validation --\n";
assert_throws(
    'ObjectId rejects short string',
    InvalidArgumentException::class,
    static fn () => new ObjectId('abc'),
);
assert_throws(
    'ObjectId rejects non-hex',
    InvalidArgumentException::class,
    static fn () => new ObjectId('zzzzzzzzzzzzzzzzzzzzzzzz'),
);
assert_throws(
    'ObjectId rejects 23-char string',
    InvalidArgumentException::class,
    static fn () => new ObjectId('507f1f77bcf86cd79943901'),
);
assert_throws(
    'ObjectId rejects 25-char string',
    InvalidArgumentException::class,
    static fn () => new ObjectId('507f1f77bcf86cd7994390111'),
);

echo "\n-- ObjectId methods --\n";
assert_true('ObjectId getTimestamp is int', is_int($oid2->getTimestamp()));
assert_equals('ObjectId getTimestamp value', 0x507f1f77, $oid2->getTimestamp());

echo "\n-- ObjectId interfaces --\n";
assert_true('ObjectId implements ObjectIdInterface', $oid instanceof ObjectIdInterface);
assert_true('ObjectId implements JsonSerializable', $oid instanceof JsonSerializable);
assert_true('ObjectId implements Type', $oid instanceof Type);
assert_true('ObjectId implements Stringable', $oid instanceof Stringable);

echo "\n-- ObjectId jsonSerialize --\n";
$json = $oid2->jsonSerialize();
assert_equals('ObjectId jsonSerialize key', '507f1f77bcf86cd799439011', $json['$oid']);

echo "\n-- ObjectId __set_state --\n";
$restored = ObjectId::__set_state(['id' => 'aabbccddeeff00112233aabb']);
assert_equals('ObjectId __set_state roundtrip', 'aabbccddeeff00112233aabb', (string) $restored);

// ============================================================
echo "\n=== UTCDateTime ===\n";
// ============================================================

echo "\n-- Construction --\n";
$utc = new UTCDateTime();
assert_true(
    'UTCDateTime default is current time (ms)',
    abs((int) (string) $utc - (int) (microtime(true) * 1000)) < 1000,
);

$utc2 = new UTCDateTime(1672531200000);
assert_equals('UTCDateTime from int', '1672531200000', (string) $utc2);

$utc3 = new UTCDateTime('1672531200000');
assert_equals('UTCDateTime from string', '1672531200000', (string) $utc3);

echo "\n-- UTCDateTime from DateTimeInterface --\n";
$dtInput = new DateTime('2023-01-01T00:00:00.000Z');
$utcFromDt = new UTCDateTime($dtInput);
assert_equals('UTCDateTime from DateTime', '1672531200000', (string) $utcFromDt);

$dtiInput = new DateTimeImmutable('2023-01-01T00:00:00.000Z');
$utcFromDti = new UTCDateTime($dtiInput);
assert_equals('UTCDateTime from DateTimeImmutable', '1672531200000', (string) $utcFromDti);

echo "\n-- UTCDateTime methods --\n";
$dt = $utc2->toDateTime();
assert_true('toDateTime returns DateTime', $dt instanceof DateTime);
assert_equals('toDateTime timestamp', 1672531200, $dt->getTimestamp());

$dti = $utc2->toDateTimeImmutable();
assert_true('toDateTimeImmutable returns DateTimeImmutable', $dti instanceof DateTimeImmutable);
assert_equals('toDateTimeImmutable timestamp', 1672531200, $dti->getTimestamp());

echo "\n-- UTCDateTime interfaces --\n";
assert_true('UTCDateTime implements UTCDateTimeInterface', $utc instanceof UTCDateTimeInterface);
assert_true('UTCDateTime implements JsonSerializable', $utc instanceof JsonSerializable);
assert_true('UTCDateTime implements Type', $utc instanceof Type);
assert_true('UTCDateTime implements Stringable', $utc instanceof Stringable);

echo "\n-- UTCDateTime jsonSerialize --\n";
$json = $utc2->jsonSerialize();
assert_equals('UTCDateTime json $numberLong', '1672531200000', $json['$date']['$numberLong']);

echo "\n-- UTCDateTime __set_state --\n";
$restored = UTCDateTime::__set_state(['milliseconds' => 1672531200000]);
assert_equals('UTCDateTime __set_state roundtrip', '1672531200000', (string) $restored);

// ============================================================
echo "\n=== Regex ===\n";
// ============================================================

echo "\n-- Construction --\n";
$regex = new Regex('foo.*bar', 'i');
assert_equals('Regex getPattern', 'foo.*bar', $regex->getPattern());
assert_equals('Regex getFlags', 'i', $regex->getFlags());
assert_equals('Regex __toString', '/foo.*bar/i', (string) $regex);

$regexNoFlags = new Regex('test');
assert_equals('Regex default flags', '', $regexNoFlags->getFlags());
assert_equals('Regex __toString no flags', '/test/', (string) $regexNoFlags);

echo "\n-- Regex interfaces --\n";
assert_true('Regex implements RegexInterface', $regex instanceof RegexInterface);
assert_true('Regex implements JsonSerializable', $regex instanceof JsonSerializable);
assert_true('Regex implements Type', $regex instanceof Type);
assert_true('Regex implements Stringable', $regex instanceof Stringable);

echo "\n-- Regex jsonSerialize --\n";
$json = $regex->jsonSerialize();
assert_equals('Regex json $regex', 'foo.*bar', $json['$regex']);
assert_equals('Regex json $options', 'i', $json['$options']);

echo "\n-- Regex __set_state --\n";
$restored = Regex::__set_state(['pattern' => 'abc', 'flags' => 'gm']);
assert_equals('Regex __set_state pattern', 'abc', $restored->getPattern());
assert_equals('Regex __set_state flags', 'gm', $restored->getFlags());

// ============================================================
echo "\n=== Binary ===\n";
// ============================================================

echo "\n-- Construction --\n";
$bin = new Binary('hello', Binary::TYPE_GENERIC);
assert_equals('Binary getData', 'hello', $bin->getData());
assert_equals('Binary getType', Binary::TYPE_GENERIC, $bin->getType());
assert_equals('Binary __toString returns data', 'hello', (string) $bin);

$binDefault = new Binary('data');
assert_equals('Binary default type is GENERIC', Binary::TYPE_GENERIC, $binDefault->getType());

echo "\n-- Binary UUID type --\n";
$uuid = new Binary('0123456789abcdef', Binary::TYPE_UUID);
assert_equals('Binary UUID type', Binary::TYPE_UUID, $uuid->getType());
assert_equals('Binary UUID type constant', 4, $uuid->getType());

echo "\n-- Binary constants --\n";
assert_equals('TYPE_GENERIC', 0, Binary::TYPE_GENERIC);
assert_equals('TYPE_FUNCTION', 1, Binary::TYPE_FUNCTION);
assert_equals('TYPE_OLD_BINARY', 2, Binary::TYPE_OLD_BINARY);
assert_equals('TYPE_OLD_UUID', 3, Binary::TYPE_OLD_UUID);
assert_equals('TYPE_UUID', 4, Binary::TYPE_UUID);
assert_equals('TYPE_MD5', 5, Binary::TYPE_MD5);
assert_equals('TYPE_ENCRYPTED', 6, Binary::TYPE_ENCRYPTED);
assert_equals('TYPE_COLUMN', 7, Binary::TYPE_COLUMN);
assert_equals('TYPE_SENSITIVE', 8, Binary::TYPE_SENSITIVE);
assert_equals('TYPE_VECTOR', 9, Binary::TYPE_VECTOR);
assert_equals('TYPE_USER_DEFINED', 128, Binary::TYPE_USER_DEFINED);

echo "\n-- Binary interfaces --\n";
assert_true('Binary implements BinaryInterface', $bin instanceof BinaryInterface);
assert_true('Binary implements JsonSerializable', $bin instanceof JsonSerializable);
assert_true('Binary implements Type', $bin instanceof Type);
assert_true('Binary implements Stringable', $bin instanceof Stringable);

echo "\n-- Binary jsonSerialize --\n";
$json = $bin->jsonSerialize();
assert_equals('Binary json base64', base64_encode('hello'), $json['$binary']['base64']);
assert_equals('Binary json subType generic', '00', $json['$binary']['subType']);

$jsonUuid = $uuid->jsonSerialize();
assert_equals('Binary json subType UUID', '04', $jsonUuid['$binary']['subType']);

$binUserDef = new Binary('x', Binary::TYPE_USER_DEFINED);
$jsonUd = $binUserDef->jsonSerialize();
assert_equals('Binary json subType user-defined', '80', $jsonUd['$binary']['subType']);

echo "\n-- Binary __set_state --\n";
$restored = Binary::__set_state(['data' => 'test', 'type' => 5]);
assert_equals('Binary __set_state data', 'test', $restored->getData());
assert_equals('Binary __set_state type', 5, $restored->getType());

// ============================================================
echo "\n=== Decimal128 ===\n";
// ============================================================

echo "\n-- Construction --\n";
$dec = new Decimal128('3.14159');
assert_equals('Decimal128 __toString', '3.14159', (string) $dec);

$decInf = new Decimal128('Infinity');
assert_equals('Decimal128 Infinity', 'Infinity', (string) $decInf);

$decNan = new Decimal128('NaN');
assert_equals('Decimal128 NaN', 'NaN', (string) $decNan);

echo "\n-- Decimal128 interfaces --\n";
assert_true('Decimal128 implements Decimal128Interface', $dec instanceof Decimal128Interface);
assert_true('Decimal128 implements JsonSerializable', $dec instanceof JsonSerializable);
assert_true('Decimal128 implements Type', $dec instanceof Type);
assert_true('Decimal128 implements Stringable', $dec instanceof Stringable);

echo "\n-- Decimal128 jsonSerialize --\n";
$json = $dec->jsonSerialize();
assert_equals('Decimal128 json value', '3.14159', $json['$numberDecimal']);

echo "\n-- Decimal128 __set_state --\n";
$restored = Decimal128::__set_state(['value' => '99.99']);
assert_equals('Decimal128 __set_state', '99.99', (string) $restored);

// ============================================================
echo "\n=== Int64 ===\n";
// ============================================================

echo "\n-- Construction --\n";
$i64 = new Int64(42);
assert_equals('Int64 from int', '42', (string) $i64);

$i64s = new Int64('9223372036854775807');
assert_equals('Int64 from string (max)', '9223372036854775807', (string) $i64s);

$i64neg = new Int64(-100);
assert_equals('Int64 negative', '-100', (string) $i64neg);

echo "\n-- Int64 interfaces --\n";
assert_true('Int64 implements JsonSerializable', $i64 instanceof JsonSerializable);
assert_true('Int64 implements Type', $i64 instanceof Type);
assert_true('Int64 implements Stringable', $i64 instanceof Stringable);

echo "\n-- Int64 jsonSerialize --\n";
assert_equals('Int64 jsonSerialize returns int', 42, $i64->jsonSerialize());

echo "\n-- Int64 __set_state --\n";
$restored = Int64::__set_state(['value' => 7]);
assert_equals('Int64 __set_state', '7', (string) $restored);

// ============================================================
echo "\n=== Timestamp ===\n";
// ============================================================

echo "\n-- Construction (increment first, timestamp second) --\n";
$ts = new Timestamp(5, 1672531200);
assert_equals('Timestamp getTimestamp', 1672531200, $ts->getTimestamp());
assert_equals('Timestamp getIncrement', 5, $ts->getIncrement());
assert_equals('Timestamp __toString', '[1672531200:5]', (string) $ts);

echo "\n-- Timestamp from strings --\n";
$tss = new Timestamp('10', '9999');
assert_equals('Timestamp from strings getTimestamp', 9999, $tss->getTimestamp());
assert_equals('Timestamp from strings getIncrement', 10, $tss->getIncrement());

echo "\n-- Timestamp increment/timestamp order --\n";
// Verify the constructor parameter order: increment FIRST
$tsOrder = new Timestamp(42, 100);
assert_equals('Timestamp order: increment=42', 42, $tsOrder->getIncrement());
assert_equals('Timestamp order: timestamp=100', 100, $tsOrder->getTimestamp());

echo "\n-- Timestamp interfaces --\n";
assert_true('Timestamp implements TimestampInterface', $ts instanceof TimestampInterface);
assert_true('Timestamp implements JsonSerializable', $ts instanceof JsonSerializable);
assert_true('Timestamp implements Type', $ts instanceof Type);
assert_true('Timestamp implements Stringable', $ts instanceof Stringable);

echo "\n-- Timestamp jsonSerialize --\n";
$json = $ts->jsonSerialize();
assert_equals('Timestamp json t', 1672531200, $json['$timestamp']['t']);
assert_equals('Timestamp json i', 5, $json['$timestamp']['i']);

echo "\n-- Timestamp __set_state --\n";
$restored = Timestamp::__set_state(['increment' => 3, 'timestamp' => 500]);
assert_equals('Timestamp __set_state timestamp', 500, $restored->getTimestamp());
assert_equals('Timestamp __set_state increment', 3, $restored->getIncrement());

// ============================================================
echo "\n=== Javascript ===\n";
// ============================================================

echo "\n-- Construction --\n";
$js = new Javascript('return x + 1;');
assert_equals('Javascript getCode', 'return x + 1;', $js->getCode());
assert_equals('Javascript getScope null', null, $js->getScope());
assert_equals('Javascript __toString', 'return x + 1;', (string) $js);

echo "\n-- Javascript with scope (array) --\n";
$jsScoped = new Javascript('return x + y;', ['x' => 1, 'y' => 2]);
assert_true('Javascript scope is object', is_object($jsScoped->getScope()));
assert_equals('Javascript scope x', 1, $jsScoped->getScope()->x);
assert_equals('Javascript scope y', 2, $jsScoped->getScope()->y);

echo "\n-- Javascript with scope (object) --\n";
$scopeObj = (object) ['a' => 10];
$jsObj = new Javascript('return a;', $scopeObj);
assert_equals('Javascript scope from object', 10, $jsObj->getScope()->a);

echo "\n-- Javascript interfaces --\n";
assert_true('Javascript implements JavascriptInterface', $js instanceof JavascriptInterface);
assert_true('Javascript implements JsonSerializable', $js instanceof JsonSerializable);
assert_true('Javascript implements Type', $js instanceof Type);
assert_true('Javascript implements Stringable', $js instanceof Stringable);

echo "\n-- Javascript jsonSerialize --\n";
$json = $js->jsonSerialize();
assert_equals('Javascript json $code (no scope)', 'return x + 1;', $json['$code']);
assert_true('Javascript json no $scope key', ! array_key_exists('$scope', $json));

$json2 = $jsScoped->jsonSerialize();
assert_equals('Javascript json $code (with scope)', 'return x + y;', $json2['$code']);
assert_true('Javascript json has $scope', array_key_exists('$scope', $json2));

echo "\n-- Javascript __set_state --\n";
$restored = Javascript::__set_state(['code' => 'return 1;', 'scope' => null]);
assert_equals('Javascript __set_state code', 'return 1;', $restored->getCode());
assert_equals('Javascript __set_state scope null', null, $restored->getScope());

// ============================================================
echo "\n=== MinKey ===\n";
// ============================================================

echo "\n-- Construction --\n";
$min = new MinKey();
assert_true('MinKey implements JsonSerializable', $min instanceof JsonSerializable);
assert_true('MinKey implements Type', $min instanceof Type);

echo "\n-- MinKey jsonSerialize --\n";
$json = $min->jsonSerialize();
assert_equals('MinKey json', 1, $json['$minKey']);

echo "\n-- MinKey __set_state --\n";
$restored = MinKey::__set_state([]);
assert_true('MinKey __set_state returns MinKey', $restored instanceof MinKey);

// ============================================================
echo "\n=== MaxKey ===\n";
// ============================================================

echo "\n-- Construction --\n";
$max = new MaxKey();
assert_true('MaxKey implements JsonSerializable', $max instanceof JsonSerializable);
assert_true('MaxKey implements Type', $max instanceof Type);

echo "\n-- MaxKey jsonSerialize --\n";
$json = $max->jsonSerialize();
assert_equals('MaxKey json', 1, $json['$maxKey']);

echo "\n-- MaxKey __set_state --\n";
$restored = MaxKey::__set_state([]);
assert_true('MaxKey __set_state returns MaxKey', $restored instanceof MaxKey);

// ============================================================
echo "\n=== Document ===\n";
// ============================================================

echo "\n-- fromPHP (array) --\n";
$doc = Document::fromPHP(['name' => 'Alice', 'age' => 30]);
assert_equals('Document get name', 'Alice', $doc->get('name'));
assert_equals('Document get age', 30, $doc->get('age'));
assert_true('Document has name', $doc->has('name'));
assert_true('Document does not have missing', ! $doc->has('missing'));
assert_equals('Document count', 2, count($doc));

echo "\n-- fromPHP (object) --\n";
$obj = (object) ['foo' => 'bar'];
$docObj = Document::fromPHP($obj);
assert_equals('Document from object', 'bar', $docObj->get('foo'));

echo "\n-- fromJSON --\n";
$docJson = Document::fromJSON('{"x": 1, "y": "two"}');
assert_equals('Document fromJSON x', 1, $docJson->get('x'));
assert_equals('Document fromJSON y', 'two', $docJson->get('y'));

echo "\n-- Document invalid JSON --\n";
assert_throws(
    'Document fromJSON invalid',
    InvalidArgumentException::class,
    static fn () => Document::fromJSON('not json'),
);

echo "\n-- Document missing key --\n";
assert_throws(
    'Document get missing key',
    OutOfRangeException::class,
    static fn () => $doc->get('nonexistent'),
);

echo "\n-- Document immutability --\n";
assert_throws(
    'Document offsetSet throws',
    LogicException::class,
    static fn () => $doc['name'] = 'Bob',
);
assert_throws(
    'Document offsetUnset throws',
    LogicException::class,
    static function () use ($doc) {
        unset($doc['name']);
    },
);

echo "\n-- Document ArrayAccess (read) --\n";
assert_equals('Document offsetGet', 'Alice', $doc['name']);
assert_true('Document offsetExists', isset($doc['name']));
assert_true('Document offsetExists false', ! isset($doc['missing']));

echo "\n-- Document toPHP --\n";
$php = $doc->toPHP();
assert_true('Document toPHP returns object', is_object($php));
assert_equals('Document toPHP name', 'Alice', $php->name);

echo "\n-- Document JSON output --\n";
$jsonStr = $doc->toCanonicalExtendedJSON();
assert_true('Document toCanonicalExtendedJSON is string', is_string($jsonStr));
$decoded = json_decode($jsonStr, true);
assert_equals('Document JSON roundtrip name', 'Alice', $decoded['name']);

assert_equals('Document __toString matches canonical', $jsonStr, (string) $doc);

echo "\n-- Document iteration --\n";
$keys = [];
foreach ($doc as $k => $v) {
    $keys[] = $k;
}

assert_equals('Document iteration keys', ['name', 'age'], $keys);

echo "\n-- Document interfaces --\n";
assert_true('Document implements IteratorAggregate', $doc instanceof IteratorAggregate);
assert_true('Document implements ArrayAccess', $doc instanceof ArrayAccess);
assert_true('Document implements Type', $doc instanceof Type);
assert_true('Document implements Stringable', $doc instanceof Stringable);
assert_true('Document implements Countable', $doc instanceof Countable);

// ============================================================
echo "\n=== PackedArray ===\n";
// ============================================================

echo "\n-- fromPHP --\n";
$arr = PackedArray::fromPHP([10, 20, 30]);
assert_equals('PackedArray get 0', 10, $arr->get(0));
assert_equals('PackedArray get 1', 20, $arr->get(1));
assert_equals('PackedArray get 2', 30, $arr->get(2));
assert_true('PackedArray has 0', $arr->has(0));
assert_true('PackedArray does not have 5', ! $arr->has(5));
assert_equals('PackedArray count', 3, count($arr));

echo "\n-- PackedArray re-indexes --\n";
$arrGap = PackedArray::fromPHP([5 => 'a', 10 => 'b']);
assert_equals('PackedArray re-index 0', 'a', $arrGap->get(0));
assert_equals('PackedArray re-index 1', 'b', $arrGap->get(1));

echo "\n-- fromJSON --\n";
$arrJson = PackedArray::fromJSON('[1, "two", 3]');
assert_equals('PackedArray fromJSON 0', 1, $arrJson->get(0));
assert_equals('PackedArray fromJSON 1', 'two', $arrJson->get(1));

echo "\n-- PackedArray invalid JSON --\n";
assert_throws(
    'PackedArray fromJSON invalid',
    InvalidArgumentException::class,
    static fn () => PackedArray::fromJSON('not json'),
);
assert_throws(
    'PackedArray fromJSON object',
    InvalidArgumentException::class,
    static fn () => PackedArray::fromJSON('{"a":1}'),
);

echo "\n-- PackedArray missing index --\n";
assert_throws(
    'PackedArray get missing index',
    OutOfRangeException::class,
    static fn () => $arr->get(99),
);

echo "\n-- PackedArray immutability --\n";
assert_throws(
    'PackedArray offsetSet throws',
    LogicException::class,
    static fn () => $arr[0] = 999,
);
assert_throws(
    'PackedArray offsetUnset throws',
    LogicException::class,
    static function () use ($arr) {
        unset($arr[0]);
    },
);

echo "\n-- PackedArray ArrayAccess (read) --\n";
assert_equals('PackedArray offsetGet', 10, $arr[0]);
assert_true('PackedArray offsetExists', isset($arr[0]));
assert_true('PackedArray offsetExists false', ! isset($arr[99]));

echo "\n-- PackedArray toPHP --\n";
$php = $arr->toPHP();
assert_true('PackedArray toPHP returns array', is_array($php));
assert_equals('PackedArray toPHP values', [10, 20, 30], $php);

echo "\n-- PackedArray JSON output --\n";
$jsonStr = $arr->toCanonicalExtendedJSON();
assert_equals('PackedArray JSON', '[10,20,30]', $jsonStr);
assert_equals('PackedArray __toString', $jsonStr, (string) $arr);

echo "\n-- PackedArray iteration --\n";
$vals = [];
foreach ($arr as $v) {
    $vals[] = $v;
}

assert_equals('PackedArray iteration', [10, 20, 30], $vals);

echo "\n-- PackedArray interfaces --\n";
assert_true('PackedArray implements IteratorAggregate', $arr instanceof IteratorAggregate);
assert_true('PackedArray implements ArrayAccess', $arr instanceof ArrayAccess);
assert_true('PackedArray implements Type', $arr instanceof Type);
assert_true('PackedArray implements Stringable', $arr instanceof Stringable);
assert_true('PackedArray implements Countable', $arr instanceof Countable);

// ============================================================
echo "\n=== Cross-type checks ===\n";
// ============================================================

echo "\n-- All types implement Type --\n";
$types = [
    'ObjectId'    => new ObjectId(),
    'UTCDateTime' => new UTCDateTime(),
    'Regex'       => new Regex('x'),
    'Binary'      => new Binary('x'),
    'Decimal128'  => new Decimal128('0'),
    'Int64'       => new Int64(0),
    'Timestamp'   => new Timestamp(0, 0),
    'Javascript'  => new Javascript(''),
    'MinKey'      => new MinKey(),
    'MaxKey'      => new MaxKey(),
    'Document'    => Document::fromPHP([]),
    'PackedArray' => PackedArray::fromPHP([]),
];

foreach ($types as $name => $instance) {
    assert_true("$name implements Type", $instance instanceof Type);
}

echo "\n-- json_encode works on all JsonSerializable types --\n";
$jsonTypes = [
    'ObjectId'    => new ObjectId('aabbccddeeff00112233aabb'),
    'UTCDateTime' => new UTCDateTime(1000),
    'Regex'       => new Regex('x', 'i'),
    'Binary'      => new Binary('data', Binary::TYPE_GENERIC),
    'Decimal128'  => new Decimal128('1.5'),
    'Int64'       => new Int64(42),
    'Timestamp'   => new Timestamp(1, 2),
    'Javascript'  => new Javascript('return 1;'),
    'MinKey'      => new MinKey(),
    'MaxKey'      => new MaxKey(),
];

foreach ($jsonTypes as $name => $instance) {
    $encoded = json_encode($instance);
    assert_true("json_encode($name) succeeds", $encoded !== false);
}

// ============================================================
echo "\n\n========================================\n";
echo "Results: $passed passed, $failed failed\n";
echo "========================================\n";

if (count($errors) > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

exit($failed > 0 ? 1 : 0);
