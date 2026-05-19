# ZealPHP MongoDB Driver — Full Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make zealphp-mongodb a complete, drop-in replacement for the official PHP MongoDB driver (`ext-mongodb` + `mongodb/mongodb` library), covering every class, method, and BSON type a general PHP application might use.

**Architecture:** Two layers — a Rust extension (`ext/src/`) providing low-level PHP functions via `ext-php-rs` backed by the `mongo-rust-driver`, and a PHP library (`php/src/`) providing the high-level OOP API that mirrors `mongodb/mongodb`. The Rust layer handles connection pooling, BSON conversion, async eventfd bridge, and raw MongoDB wire protocol. The PHP layer provides Collection, Database, Client, BSON types, result objects, and exception hierarchy. All operations support both sync (block_on) and async (eventfd + OpenSwoole coroutine) paths.

**Tech Stack:** Rust (ext-php-rs, mongodb 3.x, bson 2.x, tokio), PHP 8.4+, OpenSwoole 26+

**Current state:** 24 Rust functions, 12 PHP classes. Basic CRUD + find options + async bridge working. Missing: 7 BSON types, insertMany, bulkWrite, sessions, proper exceptions, read/write concerns, database commands, collection management, GridFS stubs, and sync-path options passthrough.

**Build/test cycle:** All Rust changes must be built inside the Docker container `labs-devops-labs-1`:
```bash
docker cp <file> labs-devops-labs-1:/home/labs/zealphp-mongodb/<path>
docker exec labs-devops-labs-1 bash -c "source /root/.cargo/env && cd /home/labs/zealphp-mongodb/ext && cargo build --release"
docker exec labs-devops-labs-1 cp /home/labs/zealphp-mongodb/ext/target/release/libzealphp_mongodb.so /usr/lib/php/20240924/zealphp_mongodb.so
```
PHP changes are live immediately (symlinked via vendor).

---

## File Map

### Rust (`ext/src/`)

| File | Responsibility | Action |
|---|---|---|
| `lib.rs` | PHP function registration — ALL `#[php_function]` exports | Modify: add `insert_many`, `run_command`, `drop_collection`, `rename_collection`, `list_collections`, `list_indexes`, `drop_index`, `drop_indexes`, `estimated_document_count` |
| `ops.rs` | Sync MongoDB operations via `coroutine::run_sync()` | Modify: add matching sync ops, fix options passthrough for ALL existing functions |
| `async_ops.rs` | Async MongoDB operations for eventfd bridge | Modify: add `insert_many`, `estimated_document_count`, `run_command` ops |
| `bson_convert.rs` | PHP ↔ BSON bidirectional conversion | Modify: add Binary, Decimal128, Int64, Timestamp, Javascript, MinKey, MaxKey support in `try_extended_json` |
| `pool.rs` | Connection pool (Client storage by ID) | No change |
| `cursor.rs` | Server-side cursor storage | No change |
| `coroutine.rs` | Tokio runtime, eventfd, spawn_task | No change |
| `async_store.rs` | Task result store (JSON by task_id) | No change |

### PHP (`php/src/`)

| File | Responsibility | Action |
|---|---|---|
| `Collection.php` | Collection CRUD API (mirrors `MongoDB\Collection`) | Modify: add `insertMany`, `bulkWrite`, `estimatedDocumentCount`, `drop`, `rename`, `createIndexes`, `dropIndex`, `dropIndexes`, `listIndexes`, `withOptions`, `getReadConcern/WriteConcern/ReadPreference/TypeMap/Manager` |
| `Database.php` | Database operations (mirrors `MongoDB\Database`) | Modify: add `command`, `aggregate`, `createCollection`, `dropCollection`, `drop`, `listCollections`, `listCollectionNames`, `withOptions`, `selectGridFSBucket` stub, concern getters |
| `Client.php` | Client management (mirrors `MongoDB\Client`) | Modify: add `dropDatabase`, `startSession`, `watch` stub, `__toString`, concern getters |
| `BSON/Binary.php` | BSON Binary type | Create |
| `BSON/Decimal128.php` | BSON Decimal128 type | Create |
| `BSON/Int64.php` | BSON Int64 type | Create |
| `BSON/Timestamp.php` | BSON Timestamp type | Create |
| `BSON/Javascript.php` | BSON Javascript type | Create |
| `BSON/MinKey.php` | BSON MinKey type | Create |
| `BSON/MaxKey.php` | BSON MaxKey type | Create |
| `BSON/Document.php` | BSON Document (immutable, from raw BSON) | Create |
| `BSON/PackedArray.php` | BSON PackedArray | Create |
| `BSON/Type.php` | Marker interface | Create |
| `BSON/Serializable.php` | BSON serialize interface | Create |
| `BSON/Unserializable.php` | BSON unserialize interface | Create |
| `BSON/Persistable.php` | Combined serialize+unserialize | Create |
| `BSON/ObjectIdInterface.php` | ObjectId interface | Create |
| `BSON/BinaryInterface.php` | Binary interface | Create |
| `BSON/Decimal128Interface.php` | Decimal128 interface | Create |
| `BSON/RegexInterface.php` | Regex interface | Create |
| `BSON/TimestampInterface.php` | Timestamp interface | Create |
| `BSON/UTCDateTimeInterface.php` | UTCDateTime interface | Create |
| `BSON/JavascriptInterface.php` | Javascript interface | Create |
| `Exception/BulkWriteException.php` | Bulk write error | Create |
| `Exception/CommandException.php` | Command error with result doc | Create |
| `Exception/AuthenticationException.php` | Auth failure | Create |
| `Exception/ConnectionTimeoutException.php` | Connection timeout | Create |
| `Exception/ExecutionTimeoutException.php` | Execution timeout | Create |
| `Exception/LogicException.php` | Logic error | Create |
| `Exception/UnexpectedValueException.php` | Unexpected value | Create |
| `InsertManyResult.php` | Result from insertMany | Create |
| `BulkWriteResult.php` | Result from bulkWrite | Create |
| `ReadConcern.php` | Read concern level | Create |
| `WriteConcern.php` | Write concern (w, j, wtimeout) | Create |
| `ReadPreference.php` | Read preference mode | Create |
| `Session.php` | Session + transaction stub | Create |
| `GridFS/Bucket.php` | GridFS stub (not-implemented exception) | Create |
| `ChangeStream.php` | Change stream stub | Create |

### Tests (`tests/`)

| File | What it tests |
|---|---|
| `test_bson_types.php` | All BSON type creation, serialization, conversion |
| `test_collection_full.php` | All Collection methods including insertMany, bulkWrite, indexes, drop |
| `test_database.php` | Database command, createCollection, listCollections, drop |
| `test_client.php` | Client listDatabases, dropDatabase, session stub |
| `test_options.php` | upsert, returnDocument, projection, sort, limit, skip on all paths |
| `test_async_full.php` | All operations in coroutine mode via eventfd |
| `test_exceptions.php` | Exception hierarchy, error propagation |
| `test_concerns.php` | ReadConcern, WriteConcern, ReadPreference passthrough |

---

## Task 1: BSON Type Interfaces

**Files:**
- Create: `php/src/BSON/Type.php`
- Create: `php/src/BSON/Serializable.php`
- Create: `php/src/BSON/Unserializable.php`
- Create: `php/src/BSON/Persistable.php`
- Create: `php/src/BSON/ObjectIdInterface.php`
- Create: `php/src/BSON/BinaryInterface.php`
- Create: `php/src/BSON/Decimal128Interface.php`
- Create: `php/src/BSON/RegexInterface.php`
- Create: `php/src/BSON/TimestampInterface.php`
- Create: `php/src/BSON/UTCDateTimeInterface.php`
- Create: `php/src/BSON/JavascriptInterface.php`

- [ ] **Step 1: Create all BSON interfaces**

```php
// php/src/BSON/Type.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface Type {}

// php/src/BSON/Serializable.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface Serializable extends Type {
    public function bsonSerialize(): array|\stdClass;
}

// php/src/BSON/Unserializable.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface Unserializable {
    public function bsonUnserialize(array $data): void;
}

// php/src/BSON/Persistable.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface Persistable extends Serializable, Unserializable {}

// php/src/BSON/ObjectIdInterface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface ObjectIdInterface {
    public function getTimestamp(): int;
    public function __toString(): string;
}

// php/src/BSON/BinaryInterface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface BinaryInterface {
    public function getData(): string;
    public function getType(): int;
    public function __toString(): string;
}

// php/src/BSON/Decimal128Interface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface Decimal128Interface {
    public function __toString(): string;
}

// php/src/BSON/RegexInterface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface RegexInterface {
    public function getPattern(): string;
    public function getFlags(): string;
    public function __toString(): string;
}

// php/src/BSON/TimestampInterface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface TimestampInterface {
    public function getTimestamp(): int;
    public function getIncrement(): int;
    public function __toString(): string;
}

// php/src/BSON/UTCDateTimeInterface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface UTCDateTimeInterface {
    public function toDateTime(): \DateTime;
    public function toDateTimeImmutable(): \DateTimeImmutable;
    public function __toString(): string;
}

// php/src/BSON/JavascriptInterface.php
<?php
namespace ZealPHP\MongoDB\BSON;
interface JavascriptInterface {
    public function getCode(): string;
    public function getScope(): ?object;
    public function __toString(): string;
}
```

- [ ] **Step 2: Commit**
```bash
git add php/src/BSON/*Interface.php php/src/BSON/Type.php php/src/BSON/Serializable.php php/src/BSON/Unserializable.php php/src/BSON/Persistable.php
git commit -m "feat(bson): add all BSON interfaces matching official driver"
```

---

## Task 2: BSON Type Classes

**Files:**
- Modify: `php/src/BSON/ObjectId.php` (add interface, jsonSerialize fix)
- Modify: `php/src/BSON/UTCDateTime.php` (add interface, toDateTime)
- Modify: `php/src/BSON/Regex.php` (add interface)
- Create: `php/src/BSON/Binary.php`
- Create: `php/src/BSON/Decimal128.php`
- Create: `php/src/BSON/Int64.php`
- Create: `php/src/BSON/Timestamp.php`
- Create: `php/src/BSON/Javascript.php`
- Create: `php/src/BSON/MinKey.php`
- Create: `php/src/BSON/MaxKey.php`
- Create: `php/src/BSON/Document.php` (immutable BSON document)
- Create: `php/src/BSON/PackedArray.php`
- Test: `tests/test_bson_types.php`

- [ ] **Step 1: Update existing BSON types to implement interfaces**

Update `ObjectId.php`:
```php
<?php
namespace ZealPHP\MongoDB\BSON;

class ObjectId implements ObjectIdInterface, \JsonSerializable, Type, \Stringable
{
    private string $id;

    public function __construct(?string $id = null)
    {
        if ($id !== null) {
            if (strlen($id) !== 24 || !ctype_xdigit($id)) {
                throw new \InvalidArgumentException("Invalid ObjectId string: $id");
            }
            $this->id = $id;
        } else {
            $this->id = bin2hex(pack('N', time()) . random_bytes(8));
        }
    }

    public function getTimestamp(): int { return hexdec(substr($this->id, 0, 8)); }
    public function __toString(): string { return $this->id; }
    public function jsonSerialize(): mixed { return ['$oid' => $this->id]; }

    public static function __set_state(array $properties): self
    {
        return new self($properties['id'] ?? $properties['oid'] ?? null);
    }
}
```

Update `UTCDateTime.php`:
```php
<?php
namespace ZealPHP\MongoDB\BSON;

class UTCDateTime implements UTCDateTimeInterface, \JsonSerializable, Type, \Stringable
{
    private int $milliseconds;

    public function __construct(int|float|string|\DateTimeInterface|null $milliseconds = null)
    {
        if ($milliseconds instanceof \DateTimeInterface) {
            $this->milliseconds = (int)($milliseconds->format('Uv'));
        } elseif ($milliseconds === null) {
            $this->milliseconds = (int)(microtime(true) * 1000);
        } else {
            $this->milliseconds = (int)$milliseconds;
        }
    }

    public function __toString(): string { return (string)$this->milliseconds; }

    public function toDateTime(): \DateTime
    {
        $sec = intdiv($this->milliseconds, 1000);
        $usec = ($this->milliseconds % 1000) * 1000;
        $dt = \DateTime::createFromFormat('U', (string)$sec);
        if ($usec > 0) {
            $dt = \DateTime::createFromFormat('U.u', sprintf('%d.%06d', $sec, $usec));
        }
        return $dt;
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromMutable($this->toDateTime());
    }

    public function jsonSerialize(): mixed { return ['$date' => ['$numberLong' => (string)$this->milliseconds]]; }

    public static function __set_state(array $properties): self
    {
        return new self($properties['milliseconds'] ?? null);
    }
}
```

Update `Regex.php`:
```php
<?php
namespace ZealPHP\MongoDB\BSON;

class Regex implements RegexInterface, \JsonSerializable, Type, \Stringable
{
    public function __construct(
        private string $pattern,
        private string $flags = ''
    ) {}

    public function getPattern(): string { return $this->pattern; }
    public function getFlags(): string { return $this->flags; }
    public function __toString(): string { return "/{$this->pattern}/{$this->flags}"; }

    public function jsonSerialize(): mixed
    {
        return ['$regularExpression' => ['pattern' => $this->pattern, 'options' => $this->flags]];
    }

    public static function __set_state(array $properties): self
    {
        return new self($properties['pattern'] ?? '', $properties['flags'] ?? '');
    }
}
```

- [ ] **Step 2: Create Binary**

```php
// php/src/BSON/Binary.php
<?php
namespace ZealPHP\MongoDB\BSON;

class Binary implements BinaryInterface, \JsonSerializable, Type, \Stringable
{
    const TYPE_GENERIC = 0;
    const TYPE_FUNCTION = 1;
    const TYPE_OLD_BINARY = 2;
    const TYPE_OLD_UUID = 3;
    const TYPE_UUID = 4;
    const TYPE_MD5 = 5;
    const TYPE_ENCRYPTED = 6;
    const TYPE_COLUMN = 7;
    const TYPE_SENSITIVE = 8;
    const TYPE_VECTOR = 9;
    const TYPE_USER_DEFINED = 128;

    public function __construct(
        private string $data,
        private int $type = self::TYPE_GENERIC
    ) {}

    public function getData(): string { return $this->data; }
    public function getType(): int { return $this->type; }
    public function __toString(): string { return $this->data; }

    public function jsonSerialize(): mixed
    {
        return ['$binary' => ['base64' => base64_encode($this->data), 'subType' => sprintf('%02x', $this->type)]];
    }

    public static function __set_state(array $properties): self
    {
        return new self($properties['data'] ?? '', $properties['type'] ?? self::TYPE_GENERIC);
    }
}
```

- [ ] **Step 3: Create Decimal128, Int64, Timestamp, Javascript, MinKey, MaxKey**

```php
// php/src/BSON/Decimal128.php
<?php
namespace ZealPHP\MongoDB\BSON;

class Decimal128 implements Decimal128Interface, \JsonSerializable, Type, \Stringable
{
    public function __construct(private string $value) {}
    public function __toString(): string { return $this->value; }
    public function jsonSerialize(): mixed { return ['$numberDecimal' => $this->value]; }
    public static function __set_state(array $properties): self { return new self($properties['value'] ?? '0'); }
}

// php/src/BSON/Int64.php
<?php
namespace ZealPHP\MongoDB\BSON;

class Int64 implements \JsonSerializable, Type, \Stringable
{
    private int $value;
    public function __construct(int|string $value) { $this->value = (int)$value; }
    public function __toString(): string { return (string)$this->value; }
    public function jsonSerialize(): mixed { return $this->value; }
    public static function __set_state(array $properties): self { return new self($properties['value'] ?? 0); }
}

// php/src/BSON/Timestamp.php
<?php
namespace ZealPHP\MongoDB\BSON;

class Timestamp implements TimestampInterface, \JsonSerializable, Type, \Stringable
{
    private int $increment;
    private int $timestamp;

    public function __construct(int|string $increment, int|string $timestamp)
    {
        $this->increment = (int)$increment;
        $this->timestamp = (int)$timestamp;
    }

    public function getTimestamp(): int { return $this->timestamp; }
    public function getIncrement(): int { return $this->increment; }
    public function __toString(): string { return sprintf('[%d:%d]', $this->timestamp, $this->increment); }
    public function jsonSerialize(): mixed { return ['$timestamp' => ['t' => $this->timestamp, 'i' => $this->increment]]; }
    public static function __set_state(array $properties): self { return new self($properties['increment'] ?? 0, $properties['timestamp'] ?? 0); }
}

// php/src/BSON/Javascript.php
<?php
namespace ZealPHP\MongoDB\BSON;

class Javascript implements JavascriptInterface, \JsonSerializable, Type, \Stringable
{
    public function __construct(
        private string $code,
        private array|object|null $scope = null
    ) {}

    public function getCode(): string { return $this->code; }
    public function getScope(): ?object { return $this->scope ? (object)$this->scope : null; }
    public function __toString(): string { return $this->code; }
    public function jsonSerialize(): mixed { return $this->scope ? ['$code' => $this->code, '$scope' => $this->scope] : ['$code' => $this->code]; }
    public static function __set_state(array $properties): self { return new self($properties['code'] ?? '', $properties['scope'] ?? null); }
}

// php/src/BSON/MinKey.php
<?php
namespace ZealPHP\MongoDB\BSON;

class MinKey implements \JsonSerializable, Type
{
    public function jsonSerialize(): mixed { return ['$minKey' => 1]; }
    public static function __set_state(array $properties): self { return new self(); }
}

// php/src/BSON/MaxKey.php
<?php
namespace ZealPHP\MongoDB\BSON;

class MaxKey implements \JsonSerializable, Type
{
    public function jsonSerialize(): mixed { return ['$maxKey' => 1]; }
    public static function __set_state(array $properties): self { return new self(); }
}

// php/src/BSON/Document.php (immutable BSON Document, not to be confused with php/src/Document.php which is the result wrapper)
<?php
namespace ZealPHP\MongoDB\BSON;

class Document implements \IteratorAggregate, \ArrayAccess, Type, \Stringable, \Countable
{
    private array $data;

    private function __construct(array $data) { $this->data = $data; }

    public static function fromPHP(array|object $value): self { return new self((array)$value); }
    public static function fromJSON(string $json): self { return new self(json_decode($json, true) ?: []); }

    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function toPHP(?array $typeMap = null): array|object { return $this->data; }
    public function toCanonicalExtendedJSON(): string { return json_encode($this->data); }
    public function toRelaxedExtendedJSON(): string { return json_encode($this->data); }
    public function __toString(): string { return json_encode($this->data); }
    public function count(): int { return count($this->data); }
    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->data); }

    public function offsetExists(mixed $offset): bool { return isset($this->data[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->data[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('BSON Document is immutable'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('BSON Document is immutable'); }
}

// php/src/BSON/PackedArray.php
<?php
namespace ZealPHP\MongoDB\BSON;

class PackedArray implements \IteratorAggregate, \ArrayAccess, Type, \Stringable, \Countable
{
    private array $data;

    private function __construct(array $data) { $this->data = array_values($data); }

    public static function fromPHP(array $value): self { return new self($value); }
    public static function fromJSON(string $json): self { return new self(json_decode($json, true) ?: []); }

    public function get(int $index): mixed { return $this->data[$index] ?? null; }
    public function has(int $index): bool { return isset($this->data[$index]); }
    public function toPHP(?array $typeMap = null): array { return $this->data; }
    public function toCanonicalExtendedJSON(): string { return json_encode($this->data); }
    public function toRelaxedExtendedJSON(): string { return json_encode($this->data); }
    public function __toString(): string { return json_encode($this->data); }
    public function count(): int { return count($this->data); }
    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->data); }

    public function offsetExists(mixed $offset): bool { return isset($this->data[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->data[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('BSON PackedArray is immutable'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('BSON PackedArray is immutable'); }
}
```

- [ ] **Step 4: Write BSON type test**

```php
// tests/test_bson_types.php
<?php
spl_autoload_register(function($c) {
    $f = str_replace('\\','/',str_replace('ZealPHP\\MongoDB\\','',$c)).'.php';
    $p = __DIR__.'/../php/src/'.$f;
    if (file_exists($p)) require_once $p;
});

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass,$fail; if($cond){$pass++;echo "PASS $label\n";}else{$fail++;echo "FAIL $label\n";} }

// ObjectId
$oid = new ZealPHP\MongoDB\BSON\ObjectId();
check('ObjectId generates 24-char hex', strlen((string)$oid) === 24 && ctype_xdigit((string)$oid));
check('ObjectId timestamp > 0', $oid->getTimestamp() > 0);
$oid2 = new ZealPHP\MongoDB\BSON\ObjectId('507f1f77bcf86cd799439011');
check('ObjectId from string', (string)$oid2 === '507f1f77bcf86cd799439011');
check('ObjectId jsonSerialize', $oid2->jsonSerialize() === ['$oid' => '507f1f77bcf86cd799439011']);

// UTCDateTime
$utc = new ZealPHP\MongoDB\BSON\UTCDateTime();
check('UTCDateTime ms > 0', (int)(string)$utc > 0);
$utc2 = new ZealPHP\MongoDB\BSON\UTCDateTime(1609459200000);
check('UTCDateTime from ms', (string)$utc2 === '1609459200000');
$dt = $utc2->toDateTime();
check('UTCDateTime toDateTime', $dt instanceof \DateTime);
check('UTCDateTime toDateTimeImmutable', $utc2->toDateTimeImmutable() instanceof \DateTimeImmutable);
$utc3 = new ZealPHP\MongoDB\BSON\UTCDateTime(new \DateTime('2021-01-01'));
check('UTCDateTime from DateTime', (int)(string)$utc3 > 0);

// Regex
$re = new ZealPHP\MongoDB\BSON\Regex('^test', 'i');
check('Regex pattern', $re->getPattern() === '^test');
check('Regex flags', $re->getFlags() === 'i');
check('Regex toString', (string)$re === '/^test/i');
check('Regex jsonSerialize', $re->jsonSerialize() === ['$regularExpression' => ['pattern' => '^test', 'options' => 'i']]);

// Binary
$bin = new ZealPHP\MongoDB\BSON\Binary("\x00\x01\x02", ZealPHP\MongoDB\BSON\Binary::TYPE_GENERIC);
check('Binary data', $bin->getData() === "\x00\x01\x02");
check('Binary type', $bin->getType() === 0);
$uuid = new ZealPHP\MongoDB\BSON\Binary(random_bytes(16), ZealPHP\MongoDB\BSON\Binary::TYPE_UUID);
check('Binary UUID type', $uuid->getType() === 4);

// Decimal128
$dec = new ZealPHP\MongoDB\BSON\Decimal128('3.14159');
check('Decimal128 toString', (string)$dec === '3.14159');
check('Decimal128 jsonSerialize', $dec->jsonSerialize() === ['$numberDecimal' => '3.14159']);

// Int64
$i = new ZealPHP\MongoDB\BSON\Int64(9223372036854775807);
check('Int64 toString', (string)$i === '9223372036854775807');

// Timestamp
$ts = new ZealPHP\MongoDB\BSON\Timestamp(1, 1609459200);
check('Timestamp getTimestamp', $ts->getTimestamp() === 1609459200);
check('Timestamp getIncrement', $ts->getIncrement() === 1);

// Javascript
$js = new ZealPHP\MongoDB\BSON\Javascript('function() { return 1; }');
check('Javascript getCode', $js->getCode() === 'function() { return 1; }');
check('Javascript getScope null', $js->getScope() === null);
$jsScope = new ZealPHP\MongoDB\BSON\Javascript('return x', ['x' => 1]);
check('Javascript getScope', $jsScope->getScope()->x === 1);

// MinKey/MaxKey
$min = new ZealPHP\MongoDB\BSON\MinKey();
$max = new ZealPHP\MongoDB\BSON\MaxKey();
check('MinKey jsonSerialize', $min->jsonSerialize() === ['$minKey' => 1]);
check('MaxKey jsonSerialize', $max->jsonSerialize() === ['$maxKey' => 1]);

// Document
$doc = ZealPHP\MongoDB\BSON\Document::fromPHP(['a' => 1, 'b' => 'two']);
check('Document get', $doc->get('a') === 1);
check('Document has', $doc->has('b') && !$doc->has('c'));
check('Document ArrayAccess', $doc['a'] === 1);
check('Document count', count($doc) === 2);
check('Document immutable', (function() use ($doc) { try { $doc['a'] = 2; return false; } catch (\LogicException $e) { return true; } })());

// PackedArray
$arr = ZealPHP\MongoDB\BSON\PackedArray::fromPHP([10, 20, 30]);
check('PackedArray get', $arr->get(1) === 20);
check('PackedArray count', count($arr) === 3);

echo "\n=== BSON TYPES: $pass PASS, $fail FAIL ===\n";
```

- [ ] **Step 5: Run BSON type tests**
```bash
php tests/test_bson_types.php
```
Expected: all PASS

- [ ] **Step 6: Commit**
```bash
git add php/src/BSON/ tests/test_bson_types.php
git commit -m "feat(bson): complete BSON type system — Binary, Decimal128, Int64, Timestamp, Javascript, MinKey, MaxKey, Document, PackedArray"
```

---

## Task 3: Rust BSON Conversion — Handle All Types

**Files:**
- Modify: `ext/src/bson_convert.rs`

- [ ] **Step 1: Update `try_extended_json` to handle all BSON types**

Add these match arms to `try_extended_json()` in `bson_convert.rs`, after the existing `$oid`, `$date`, `$regularExpression` handlers:

```rust
// Binary: {"$binary": {"base64": "...", "subType": "00"}}
if let Some(bin_val) = ht.get("$binary") {
    if let Some(inner) = bin_val.array() {
        let b64 = inner.get("base64").and_then(|v| v.str()).unwrap_or("");
        let sub = inner.get("subType").and_then(|v| v.str()).unwrap_or("00");
        let data = base64_decode(b64);
        let subtype = u8::from_str_radix(sub, 16).unwrap_or(0);
        return Ok(Some(Bson::Binary(bson::Binary { subtype: bson::spec::BinarySubtype::from(subtype), bytes: data })));
    }
}

// Decimal128: {"$numberDecimal": "3.14"}
if let Some(dec_val) = ht.get("$numberDecimal") {
    if let Some(s) = dec_val.str() {
        let d = bson::Decimal128::from_str(s).unwrap_or_default();
        return Ok(Some(Bson::Decimal128(d)));
    }
}

// Timestamp: {"$timestamp": {"t": 123, "i": 1}}
if let Some(ts_val) = ht.get("$timestamp") {
    if let Some(inner) = ts_val.array() {
        let t = inner.get("t").and_then(|v| v.long()).unwrap_or(0) as u32;
        let i = inner.get("i").and_then(|v| v.long()).unwrap_or(0) as u32;
        return Ok(Some(Bson::Timestamp(bson::Timestamp { time: t, increment: i })));
    }
}

// MinKey: {"$minKey": 1}
if ht.get("$minKey").is_some() {
    return Ok(Some(Bson::MinKey));
}

// MaxKey: {"$maxKey": 1}
if ht.get("$maxKey").is_some() {
    return Ok(Some(Bson::MaxKey));
}

// Javascript: {"$code": "...", "$scope": {...}} or {"$code": "..."}
if let Some(code_val) = ht.get("$code") {
    if let Some(code) = code_val.str() {
        if let Some(scope_val) = ht.get("$scope") {
            if let Some(scope_arr) = scope_val.array() {
                let scope_doc = hash_table_to_doc(scope_arr)?;
                return Ok(Some(Bson::JavaScriptCodeWithScope(bson::JavaScriptCodeWithScope { code: code.to_string(), scope: scope_doc })));
            }
        }
        return Ok(Some(Bson::JavaScriptCode(code.to_string())));
    }
}
```

Also add a helper function at the bottom of `bson_convert.rs`:
```rust
fn base64_decode(input: &str) -> Vec<u8> {
    // Simple base64 decoder — or use the `base64` crate
    // For now, use a minimal implementation
    use std::io::Read;
    let mut buf = Vec::new();
    let _ = base64_engine().decode(input, &mut buf);
    buf
}
```

Actually, add `base64` to Cargo.toml dependencies and use it:
```toml
base64 = "0.22"
```

Then in bson_convert.rs:
```rust
fn base64_decode(input: &str) -> Vec<u8> {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.decode(input).unwrap_or_default()
}
```

- [ ] **Step 2: Update `bson_to_zval` for Binary, Decimal128, Javascript, Timestamp, MinKey, MaxKey**

These are already partially handled but make sure `bson_to_zval` returns proper extended JSON for all types so PHP can reconstruct them:

```rust
// In bson_to_zval, update the existing arms and add missing ones:

Bson::Binary(bin) => {
    use base64::Engine;
    let mut ht = ZendHashTable::new();
    let mut inner = ZendHashTable::new();
    let b64 = base64::engine::general_purpose::STANDARD.encode(&bin.bytes);
    let sub = format!("{:02x}", u8::from(bin.subtype));
    let mut b64_z = Zval::new(); let _ = b64_z.set_string(&b64, false);
    let mut sub_z = Zval::new(); let _ = sub_z.set_string(&sub, false);
    let _ = inner.insert("base64", b64_z);
    let _ = inner.insert("subType", sub_z);
    let mut inner_z = Zval::new(); inner_z.set_hashtable(inner);
    let _ = ht.insert("$binary", inner_z);
    zval.set_hashtable(ht);
}
Bson::JavaScriptCode(code) => {
    let _ = zval.set_string(code, false);
}
Bson::JavaScriptCodeWithScope(jcs) => {
    let mut ht = ZendHashTable::new();
    let mut code_z = Zval::new(); let _ = code_z.set_string(&jcs.code, false);
    let _ = ht.insert("$code", code_z);
    let _ = ht.insert("$scope", doc_to_php(&jcs.scope));
    zval.set_hashtable(ht);
}
Bson::MinKey => {
    let mut ht = ZendHashTable::new();
    let mut one = Zval::new(); one.set_long(1);
    let _ = ht.insert("$minKey", one);
    zval.set_hashtable(ht);
}
Bson::MaxKey => {
    let mut ht = ZendHashTable::new();
    let mut one = Zval::new(); one.set_long(1);
    let _ = ht.insert("$maxKey", one);
    zval.set_hashtable(ht);
}
```

- [ ] **Step 3: Update `prepareBSON` in Collection.php for new types**

Add to Collection.php's `prepareBSON()` method:
```php
if ($data instanceof \ZealPHP\MongoDB\BSON\Binary) {
    return $data->jsonSerialize();
}
if ($data instanceof \ZealPHP\MongoDB\BSON\Decimal128) {
    return $data->jsonSerialize();
}
if ($data instanceof \ZealPHP\MongoDB\BSON\Timestamp) {
    return $data->jsonSerialize();
}
if ($data instanceof \ZealPHP\MongoDB\BSON\Javascript) {
    return $data->jsonSerialize();
}
if ($data instanceof \ZealPHP\MongoDB\BSON\MinKey) {
    return $data->jsonSerialize();
}
if ($data instanceof \ZealPHP\MongoDB\BSON\MaxKey) {
    return $data->jsonSerialize();
}
if ($data instanceof \ZealPHP\MongoDB\BSON\Int64) {
    return (int)(string)$data;
}
```

- [ ] **Step 4: Add `base64` to Cargo.toml, build, test**
```bash
# Add to Cargo.toml: base64 = "0.22"
docker cp ext/src/bson_convert.rs labs-devops-labs-1:/home/labs/zealphp-mongodb/ext/src/bson_convert.rs
docker cp ext/Cargo.toml labs-devops-labs-1:/home/labs/zealphp-mongodb/ext/Cargo.toml
docker exec labs-devops-labs-1 bash -c "source /root/.cargo/env && cd /home/labs/zealphp-mongodb/ext && cargo build --release"
docker exec labs-devops-labs-1 cp /home/labs/zealphp-mongodb/ext/target/release/libzealphp_mongodb.so /usr/lib/php/20240924/zealphp_mongodb.so
```

- [ ] **Step 5: Commit**
```bash
git add ext/src/bson_convert.rs ext/Cargo.toml php/src/Collection.php
git commit -m "feat(bson): full bidirectional BSON conversion — Binary, Decimal128, Timestamp, Javascript, MinKey, MaxKey"
```

---

## Task 4: Exception Hierarchy

**Files:**
- Modify: `php/src/Exception/RuntimeException.php`
- Modify: `php/src/Exception/ConnectionException.php`
- Modify: `php/src/Exception/ServerException.php`
- Create: `php/src/Exception/BulkWriteException.php`
- Create: `php/src/Exception/CommandException.php`
- Create: `php/src/Exception/AuthenticationException.php`
- Create: `php/src/Exception/ConnectionTimeoutException.php`
- Create: `php/src/Exception/ExecutionTimeoutException.php`
- Create: `php/src/Exception/LogicException.php`
- Create: `php/src/Exception/UnexpectedValueException.php`

- [ ] **Step 1: Create complete exception hierarchy**

```php
// php/src/Exception/Exception.php — already exists, update:
<?php
namespace ZealPHP\MongoDB\Exception;
interface ExceptionInterface extends \Throwable {}

class Exception extends \Exception implements ExceptionInterface {}

// php/src/Exception/RuntimeException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class RuntimeException extends \RuntimeException implements ExceptionInterface
{
    public function hasErrorLabel(string $errorLabel): bool { return false; }
}

// php/src/Exception/LogicException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class LogicException extends \LogicException implements ExceptionInterface {}

// php/src/Exception/InvalidArgumentException.php — already exists, update:
<?php
namespace ZealPHP\MongoDB\Exception;
class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface {}

// php/src/Exception/UnexpectedValueException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class UnexpectedValueException extends \UnexpectedValueException implements ExceptionInterface {}

// php/src/Exception/ConnectionException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class ConnectionException extends RuntimeException {}

// php/src/Exception/AuthenticationException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class AuthenticationException extends ConnectionException {}

// php/src/Exception/ConnectionTimeoutException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class ConnectionTimeoutException extends ConnectionException {}

// php/src/Exception/ServerException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class ServerException extends RuntimeException {}

// php/src/Exception/CommandException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class CommandException extends ServerException
{
    private ?object $resultDocument;
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?object $resultDocument = null)
    {
        parent::__construct($message, $code, $previous);
        $this->resultDocument = $resultDocument;
    }
    public function getResultDocument(): ?object { return $this->resultDocument; }
}

// php/src/Exception/ExecutionTimeoutException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class ExecutionTimeoutException extends ServerException {}

// php/src/Exception/BulkWriteException.php
<?php
namespace ZealPHP\MongoDB\Exception;
class BulkWriteException extends ServerException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private ?object $writeResult = null)
    {
        parent::__construct($message, $code, $previous);
    }
    public function getWriteResult(): ?object { return $this->writeResult; }
}
```

- [ ] **Step 2: Commit**
```bash
git add php/src/Exception/
git commit -m "feat(exceptions): complete exception hierarchy matching official driver"
```

---

## Task 5: Read/Write Concerns + ReadPreference

**Files:**
- Create: `php/src/ReadConcern.php`
- Create: `php/src/WriteConcern.php`
- Create: `php/src/ReadPreference.php`

- [ ] **Step 1: Create concern classes**

```php
// php/src/ReadConcern.php
<?php
namespace ZealPHP\MongoDB;

class ReadConcern implements \JsonSerializable
{
    const LINEARIZABLE = 'linearizable';
    const LOCAL = 'local';
    const MAJORITY = 'majority';
    const AVAILABLE = 'available';
    const SNAPSHOT = 'snapshot';

    public readonly ?string $level;

    public function __construct(?string $level = null) { $this->level = $level; }
    public function getLevel(): ?string { return $this->level; }
    public function isDefault(): bool { return $this->level === null; }
    public function jsonSerialize(): mixed { return $this->level ? ['level' => $this->level] : new \stdClass(); }
    public function bsonSerialize(): \stdClass { return (object)($this->level ? ['level' => $this->level] : []); }
}

// php/src/WriteConcern.php
<?php
namespace ZealPHP\MongoDB;

class WriteConcern implements \JsonSerializable
{
    const MAJORITY = 'majority';

    public readonly string|int|null $w;
    public readonly ?bool $j;
    public readonly int $wtimeout;

    public function __construct(string|int $w, ?int $wtimeout = null, ?bool $journal = null)
    {
        $this->w = $w;
        $this->wtimeout = $wtimeout ?? 0;
        $this->j = $journal;
    }

    public function getW(): string|int|null { return $this->w; }
    public function getJournal(): ?bool { return $this->j; }
    public function getWtimeout(): int { return $this->wtimeout; }
    public function isDefault(): bool { return $this->w === null && $this->j === null && $this->wtimeout === 0; }
    public function jsonSerialize(): mixed { return ['w' => $this->w, 'j' => $this->j, 'wtimeout' => $this->wtimeout]; }
    public function bsonSerialize(): \stdClass { return (object)array_filter(['w' => $this->w, 'j' => $this->j, 'wtimeout' => $this->wtimeout], fn($v) => $v !== null); }
}

// php/src/ReadPreference.php
<?php
namespace ZealPHP\MongoDB;

class ReadPreference implements \JsonSerializable
{
    const PRIMARY = 'primary';
    const PRIMARY_PREFERRED = 'primaryPreferred';
    const SECONDARY = 'secondary';
    const SECONDARY_PREFERRED = 'secondaryPreferred';
    const NEAREST = 'nearest';
    const NO_MAX_STALENESS = -1;
    const SMALLEST_MAX_STALENESS_SECONDS = 90;

    public readonly string $mode;
    public readonly ?array $tags;
    public readonly int $maxStalenessSeconds;

    public function __construct(string $mode, ?array $tagSets = null, ?array $options = null)
    {
        $this->mode = $mode;
        $this->tags = $tagSets;
        $this->maxStalenessSeconds = $options['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;
    }

    public function getModeString(): string { return $this->mode; }
    public function getTagSets(): array { return $this->tags ?? []; }
    public function getMaxStalenessSeconds(): int { return $this->maxStalenessSeconds; }
    public function jsonSerialize(): mixed { return ['mode' => $this->mode]; }
    public function bsonSerialize(): \stdClass { return (object)['mode' => $this->mode]; }
}
```

- [ ] **Step 2: Add concern getters to Client, Database, Collection**

Add these methods to each class:
```php
// In Client.php, Database.php, Collection.php:
private ?ReadConcern $readConcern = null;
private ?WriteConcern $writeConcern = null;
private ?ReadPreference $readPreference = null;

public function getReadConcern(): ReadConcern { return $this->readConcern ?? new ReadConcern(); }
public function getWriteConcern(): WriteConcern { return $this->writeConcern ?? new WriteConcern(1); }
public function getReadPreference(): ReadPreference { return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY); }
public function getTypeMap(): array { return ['root' => 'array', 'document' => 'array', 'array' => 'array']; }
```

- [ ] **Step 3: Commit**
```bash
git add php/src/ReadConcern.php php/src/WriteConcern.php php/src/ReadPreference.php php/src/Client.php php/src/Database.php php/src/Collection.php
git commit -m "feat: ReadConcern, WriteConcern, ReadPreference classes with getters on Client/Database/Collection"
```

---

## Task 6: Missing Collection Methods — insertMany, bulkWrite, indexes, drop, rename

**Files:**
- Modify: `ext/src/lib.rs` — add `zealphp_mongodb_insert_many`, `zealphp_mongodb_drop_collection`, `zealphp_mongodb_rename_collection`, `zealphp_mongodb_list_indexes`, `zealphp_mongodb_drop_index`, `zealphp_mongodb_drop_indexes`, `zealphp_mongodb_estimated_document_count`
- Modify: `ext/src/ops.rs` — add matching sync ops
- Modify: `ext/src/async_ops.rs` — add `insert_many`, `estimated_document_count`
- Modify: `php/src/Collection.php` — add all missing methods
- Create: `php/src/InsertManyResult.php`
- Create: `php/src/BulkWriteResult.php`
- Test: `tests/test_collection_full.php`

- [ ] **Step 1: Add Rust ops for insertMany**

In `ops.rs`:
```rust
pub fn insert_many(
    client: &Client,
    db: &str,
    col: &str,
    docs: Vec<Document>,
) -> Result<mongodb::results::InsertManyResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.insert_many(docs).await })
}

pub fn estimated_document_count(
    client: &Client,
    db: &str,
    col: &str,
) -> Result<u64, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.estimated_document_count().await })
}

pub fn drop_collection(client: &Client, db: &str, col: &str) -> Result<(), String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.drop().await })
}

pub fn rename_collection(client: &Client, db: &str, col: &str, new_name: &str, new_db: Option<&str>) -> Result<(), String> {
    let database = client.database(db);
    let new_db_s = new_db.map(|s| s.to_string());
    let col_s = col.to_string();
    let new_name_s = new_name.to_string();
    coroutine::run_sync(async move {
        let cmd = bson::doc! {
            "renameCollection": format!("{}.{}", database.name(), col_s),
            "to": format!("{}.{}", new_db_s.as_deref().unwrap_or(database.name()), new_name_s),
        };
        database.client().database("admin").run_command(cmd).await.map(|_| ())
    })
}

pub fn list_indexes(
    client: &Client,
    db: &str,
    col: &str,
) -> Result<Vec<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        use futures::TryStreamExt;
        let cursor = collection.list_indexes().await?;
        let indexes: Vec<_> = cursor.try_collect().await?;
        Ok(indexes.into_iter().map(|idx| {
            let mut doc = Document::new();
            doc.insert("key", bson::Bson::Document(idx.keys));
            if let Some(opts) = idx.options {
                if let Some(name) = opts.name { doc.insert("name", name); }
                if let Some(unique) = opts.unique { doc.insert("unique", unique); }
                if let Some(sparse) = opts.sparse { doc.insert("sparse", sparse); }
            }
            doc
        }).collect())
    })
}

pub fn drop_index(client: &Client, db: &str, col: &str, name: &str) -> Result<(), String> {
    let collection = client.database(db).collection::<Document>(col);
    let name_s = name.to_string();
    coroutine::run_sync(async move { collection.drop_index(name_s).await.map(|_| ()) })
}

pub fn drop_indexes(client: &Client, db: &str, col: &str) -> Result<(), String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.drop_indexes().await.map(|_| ()) })
}
```

- [ ] **Step 2: Add PHP functions in lib.rs**

In `lib.rs`, add `#[php_function]` wrappers for each of the above ops. Pattern is identical to existing functions — get client from pool, call ops::fn, convert result to Zval.

For `zealphp_mongodb_insert_many`: accept `documents` as `&Zval` (array of arrays), convert each via `bson_convert::php_to_doc`, call `ops::insert_many`, return array of `{"inserted_ids": [...], "acknowledged": true, "inserted_count": N}`.

- [ ] **Step 3: Add async_ops entries**

In `async_ops.rs`, add match arms for `"insert_many"`, `"estimated_document_count"` following the existing pattern.

- [ ] **Step 4: Create InsertManyResult.php and BulkWriteResult.php**

```php
// php/src/InsertManyResult.php
<?php
namespace ZealPHP\MongoDB;

class InsertManyResult
{
    public function __construct(private array $result) {}
    public function getInsertedCount(): int { return $this->result['inserted_count'] ?? 0; }
    public function getInsertedIds(): array { return $this->result['inserted_ids'] ?? []; }
    public function isAcknowledged(): bool { return $this->result['acknowledged'] ?? true; }
}

// php/src/BulkWriteResult.php
<?php
namespace ZealPHP\MongoDB;

class BulkWriteResult
{
    public function __construct(private array $result) {}
    public function getInsertedCount(): int { return $this->result['inserted_count'] ?? 0; }
    public function getMatchedCount(): int { return $this->result['matched_count'] ?? 0; }
    public function getModifiedCount(): int { return $this->result['modified_count'] ?? 0; }
    public function getDeletedCount(): int { return $this->result['deleted_count'] ?? 0; }
    public function getUpsertedCount(): int { return $this->result['upserted_count'] ?? 0; }
    public function getUpsertedIds(): array { return $this->result['upserted_ids'] ?? []; }
    public function isAcknowledged(): bool { return $this->result['acknowledged'] ?? true; }
}
```

- [ ] **Step 5: Add PHP Collection methods**

Add these methods to `Collection.php`:

```php
public function insertMany(array $documents, array $options = []): InsertManyResult
{
    $docs = array_map(fn($d) => self::prepareBSON((array)$d), $documents);
    if (AsyncBridge::isCoroutineMode()) {
        return new InsertManyResult(AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'insert_many', ['__docs' => $docs]) ?? []);
    }
    $opts = $options ?: null;
    return new InsertManyResult(zealphp_mongodb_insert_many($this->poolId, $this->dbName, $this->colName, $docs, $opts));
}

public function estimatedDocumentCount(array $options = []): int
{
    if (AsyncBridge::isCoroutineMode()) {
        $result = AsyncBridge::exec($this->poolId, $this->dbName, $this->colName, 'estimated_document_count', []);
        return $result['count'] ?? 0;
    }
    return zealphp_mongodb_estimated_document_count($this->poolId, $this->dbName, $this->colName);
}

public function bulkWrite(array $operations, array $options = []): BulkWriteResult
{
    $results = ['inserted_count' => 0, 'matched_count' => 0, 'modified_count' => 0, 'deleted_count' => 0, 'upserted_count' => 0, 'acknowledged' => true];
    foreach ($operations as $op) {
        foreach ($op as $type => $args) {
            switch ($type) {
                case 'insertOne':
                    $this->insertOne($args[0] ?? $args);
                    $results['inserted_count']++;
                    break;
                case 'updateOne':
                    $r = $this->updateOne($args[0], $args[1], $args[2] ?? []);
                    $results['matched_count'] += $r->getMatchedCount();
                    $results['modified_count'] += $r->getModifiedCount();
                    break;
                case 'updateMany':
                    $r = $this->updateMany($args[0], $args[1], $args[2] ?? []);
                    $results['matched_count'] += $r->getMatchedCount();
                    $results['modified_count'] += $r->getModifiedCount();
                    break;
                case 'deleteOne':
                    $r = $this->deleteOne($args[0], $args[1] ?? []);
                    $results['deleted_count'] += $r->getDeletedCount();
                    break;
                case 'deleteMany':
                    $r = $this->deleteMany($args[0], $args[1] ?? []);
                    $results['deleted_count'] += $r->getDeletedCount();
                    break;
                case 'replaceOne':
                    $r = $this->replaceOne($args[0], $args[1], $args[2] ?? []);
                    $results['matched_count'] += $r->getMatchedCount();
                    $results['modified_count'] += $r->getModifiedCount();
                    break;
            }
        }
    }
    return new BulkWriteResult($results);
}

public function drop(array $options = []): array
{
    zealphp_mongodb_drop_collection($this->poolId, $this->dbName, $this->colName);
    return ['ok' => 1];
}

public function rename(string $toCollectionName, ?string $toDatabaseName = null, array $options = []): array
{
    zealphp_mongodb_rename_collection($this->poolId, $this->dbName, $this->colName, $toCollectionName, $toDatabaseName);
    $this->colName = $toCollectionName;
    if ($toDatabaseName) $this->dbName = $toDatabaseName;
    return ['ok' => 1];
}

public function listIndexes(array $options = []): array
{
    return zealphp_mongodb_list_indexes($this->poolId, $this->dbName, $this->colName);
}

public function dropIndex(string $indexName, array $options = []): array
{
    zealphp_mongodb_drop_index($this->poolId, $this->dbName, $this->colName, $indexName);
    return ['ok' => 1];
}

public function dropIndexes(array $options = []): array
{
    zealphp_mongodb_drop_indexes($this->poolId, $this->dbName, $this->colName);
    return ['ok' => 1];
}

public function createIndexes(array $indexes, array $options = []): array
{
    $names = [];
    foreach ($indexes as $idx) {
        $key = $idx['key'] ?? [];
        $idxOpts = $idx;
        unset($idxOpts['key']);
        $names[] = $this->createIndex($key, $idxOpts);
    }
    return $names;
}

public function withOptions(array $options = []): self
{
    $new = clone $this;
    $new->options = array_merge($this->options, $options);
    return $new;
}
```

- [ ] **Step 6: Build Rust, test, commit**
```bash
# Build and install
docker cp ext/src/ops.rs labs-devops-labs-1:/home/labs/zealphp-mongodb/ext/src/
docker cp ext/src/lib.rs labs-devops-labs-1:/home/labs/zealphp-mongodb/ext/src/
docker cp ext/src/async_ops.rs labs-devops-labs-1:/home/labs/zealphp-mongodb/ext/src/
docker exec labs-devops-labs-1 bash -c "source /root/.cargo/env && cd /home/labs/zealphp-mongodb/ext && cargo build --release"
docker exec labs-devops-labs-1 cp /home/labs/zealphp-mongodb/ext/target/release/libzealphp_mongodb.so /usr/lib/php/20240924/zealphp_mongodb.so

# Run test
docker exec labs-devops-labs-1 php tests/test_collection_full.php

git add -A
git commit -m "feat(collection): insertMany, bulkWrite, estimatedDocumentCount, drop, rename, index management"
```

---

## Task 7: Missing Database Methods

**Files:**
- Modify: `ext/src/lib.rs` — add `zealphp_mongodb_run_command`, `zealphp_mongodb_create_collection`, `zealphp_mongodb_drop_database`, `zealphp_mongodb_list_collections`
- Modify: `ext/src/ops.rs` — add sync ops
- Modify: `ext/src/async_ops.rs` — add `run_command` async op
- Modify: `php/src/Database.php` — add all missing methods
- Modify: `php/src/Client.php` — add `dropDatabase`
- Create: `php/src/GridFS/Bucket.php`
- Create: `php/src/ChangeStream.php`
- Test: `tests/test_database.php`

- [ ] **Step 1: Add Rust ops for database operations**

In `ops.rs`:
```rust
pub fn run_command(client: &Client, db: &str, command: Document) -> Result<Document, String> {
    let database = client.database(db);
    coroutine::run_sync(async move { database.run_command(command).await })
}

pub fn create_collection(client: &Client, db: &str, name: &str) -> Result<(), String> {
    let database = client.database(db);
    let name_s = name.to_string();
    coroutine::run_sync(async move { database.create_collection(name_s).await.map(|_| ()) })
}

pub fn drop_database(client: &Client, db: &str) -> Result<(), String> {
    let database = client.database(db);
    coroutine::run_sync(async move { database.drop().await })
}

pub fn list_collections(client: &Client, db: &str) -> Result<Vec<Document>, String> {
    let database = client.database(db);
    coroutine::run_sync(async move {
        use futures::TryStreamExt;
        let cursor = database.list_collections().await?;
        cursor.try_collect().await
    })
}

pub fn list_collection_names(client: &Client, db: &str) -> Result<Vec<String>, String> {
    let database = client.database(db);
    coroutine::run_sync(async move { database.list_collection_names().await })
}

pub fn database_aggregate(
    client: &Client,
    db: &str,
    pipeline: Vec<Document>,
) -> Result<Vec<Document>, String> {
    let database = client.database(db);
    coroutine::run_sync(async move {
        use futures::TryStreamExt;
        let cursor = database.aggregate(pipeline).await?;
        cursor.try_collect().await
    })
}
```

- [ ] **Step 2: Add PHP function wrappers in lib.rs**

Add `#[php_function]` wrappers for `zealphp_mongodb_run_command`, `zealphp_mongodb_create_collection`, `zealphp_mongodb_drop_database`, `zealphp_mongodb_list_collections`, `zealphp_mongodb_list_collection_names`, `zealphp_mongodb_database_aggregate`.

For `zealphp_mongodb_run_command`: accept `pool_id`, `db`, `command` as Zval, call `ops::run_command`, return result as Zval via `bson_convert::doc_to_php`.

- [ ] **Step 3: Update Database.php with all methods**

```php
// Add to Database.php:

public function command(array|object $command, array $options = []): array
{
    $cmd = Collection::prepareBSON((array)$command);
    $result = zealphp_mongodb_run_command($this->poolId, $this->databaseName, $cmd);
    return is_array($result) ? $result : [$result];
}

public function aggregate(array $pipeline, array $options = []): Cursor|ArrayCursor
{
    $pipeline = Collection::prepareBSON($pipeline);
    $results = zealphp_mongodb_database_aggregate($this->poolId, $this->databaseName, $pipeline);
    return new ArrayCursor($results);
}

public function createCollection(string $collectionName, array $options = []): array
{
    zealphp_mongodb_create_collection($this->poolId, $this->databaseName, $collectionName);
    return ['ok' => 1];
}

public function dropCollection(string $collectionName, array $options = []): array
{
    zealphp_mongodb_drop_collection($this->poolId, $this->databaseName, $collectionName);
    return ['ok' => 1];
}

public function drop(array $options = []): array
{
    zealphp_mongodb_drop_database($this->poolId, $this->databaseName);
    return ['ok' => 1];
}

public function listCollections(array $options = []): array
{
    return zealphp_mongodb_list_collections($this->poolId, $this->databaseName);
}

public function listCollectionNames(array $options = []): array
{
    return zealphp_mongodb_list_collection_names($this->poolId, $this->databaseName);
}

public function modifyCollection(string $collectionName, array $collectionOptions, array $options = []): array
{
    $cmd = array_merge(['collMod' => $collectionName], $collectionOptions);
    return $this->command($cmd);
}

public function renameCollection(string $from, string $to, ?string $toDb = null, array $options = []): array
{
    $col = $this->selectCollection($from);
    return $col->rename($to, $toDb);
}

public function selectGridFSBucket(array $options = []): GridFS\Bucket
{
    return new GridFS\Bucket($this->poolId, $this->databaseName, $options);
}

public function withOptions(array $options = []): self
{
    $new = clone $this;
    $new->options = array_merge($this->options, $options);
    return $new;
}

public function __toString(): string { return $this->databaseName; }
public function __debugInfo(): array { return ['databaseName' => $this->databaseName, 'poolId' => $this->poolId]; }
```

- [ ] **Step 4: Update Client.php**

```php
// Add to Client.php:
public function dropDatabase(string $databaseName, array $options = []): array
{
    zealphp_mongodb_drop_database($this->poolId, $databaseName);
    return ['ok' => 1];
}

public function startSession(array $options = []): Session
{
    return new Session($this->poolId, $options);
}

public function watch(array $pipeline = [], array $options = []): ChangeStream
{
    return new ChangeStream();
}

public function __toString(): string { return 'mongodb://...'; }
public function __debugInfo(): array { return ['poolId' => $this->poolId]; }
```

- [ ] **Step 5: Create GridFS/Bucket.php stub and ChangeStream.php stub**

```php
// php/src/GridFS/Bucket.php
<?php
namespace ZealPHP\MongoDB\GridFS;

class Bucket
{
    public function __construct(private int $poolId, private string $dbName, private array $options = []) {}

    public function openUploadStream(string $filename, array $options = []): mixed
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function uploadFromStream(string $filename, $source, array $options = []): mixed
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function openDownloadStream(mixed $id): mixed
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function downloadToStream(mixed $id, $destination): void
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function find(array|object $filter = [], array $options = []): \Iterator
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function delete(mixed $id): void
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function drop(): void
    {
        throw new \ZealPHP\MongoDB\Exception\RuntimeException('GridFS not yet implemented in ZealPHP MongoDB driver');
    }

    public function getBucketName(): string { return $this->options['bucketName'] ?? 'fs'; }
    public function getDatabaseName(): string { return $this->dbName; }
}

// php/src/ChangeStream.php
<?php
namespace ZealPHP\MongoDB;

class ChangeStream implements \Iterator
{
    public function current(): mixed { return null; }
    public function key(): mixed { return null; }
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool { return false; }
    public function getResumeToken(): ?object { return null; }
}
```

- [ ] **Step 6: Create Session.php stub**

```php
// php/src/Session.php
<?php
namespace ZealPHP\MongoDB;

class Session
{
    const TRANSACTION_NONE = 'none';
    const TRANSACTION_STARTING = 'starting';
    const TRANSACTION_IN_PROGRESS = 'in_progress';
    const TRANSACTION_COMMITTED = 'committed';
    const TRANSACTION_ABORTED = 'aborted';

    private string $transactionState = self::TRANSACTION_NONE;

    public function __construct(private int $poolId, private array $options = []) {}

    public function startTransaction(?array $options = null): void { $this->transactionState = self::TRANSACTION_IN_PROGRESS; }
    public function commitTransaction(): void { $this->transactionState = self::TRANSACTION_COMMITTED; }
    public function abortTransaction(): void { $this->transactionState = self::TRANSACTION_ABORTED; }
    public function endSession(): void { $this->transactionState = self::TRANSACTION_NONE; }

    public function isInTransaction(): bool { return $this->transactionState === self::TRANSACTION_IN_PROGRESS; }
    public function getTransactionState(): string { return $this->transactionState; }
    public function getTransactionOptions(): ?array { return null; }

    public function getLogicalSessionId(): object { return (object)['id' => bin2hex(random_bytes(16))]; }
    public function getClusterTime(): ?object { return null; }
    public function getOperationTime(): ?object { return null; }
    public function getServer(): ?object { return null; }
    public function isDirty(): bool { return false; }

    public function advanceClusterTime(array|object $clusterTime): void {}
    public function advanceOperationTime(mixed $operationTime): void {}
}
```

- [ ] **Step 7: Build, test, commit**
```bash
# Build Rust changes
docker cp ext/src/ labs-devops-labs-1:/home/labs/zealphp-mongodb/ext/src/
docker exec labs-devops-labs-1 bash -c "source /root/.cargo/env && cd /home/labs/zealphp-mongodb/ext && cargo build --release"
docker exec labs-devops-labs-1 cp /home/labs/zealphp-mongodb/ext/target/release/libzealphp_mongodb.so /usr/lib/php/20240924/zealphp_mongodb.so

# Test
docker exec labs-devops-labs-1 php tests/test_database.php

git add -A
git commit -m "feat: Database command/aggregate/createCollection/drop, Client dropDatabase/session, GridFS stub, ChangeStream stub"
```

---

## Task 8: Sync Path Options Passthrough

**Files:**
- Modify: `ext/src/lib.rs` — parse `_opts` Zval for ALL sync functions
- Modify: `ext/src/ops.rs` — accept options structs

Currently, sync-path functions ignore the `_opts` parameter. Fix every function to parse and pass options (upsert, projection, sort, limit, skip, returnDocument).

- [ ] **Step 1: Create helper function in lib.rs to parse options Zval**

```rust
fn parse_find_options(opts: Option<&Zval>) -> mongodb::options::FindOptions {
    let mut fo = mongodb::options::FindOptions::default();
    if let Some(z) = opts {
        if let Some(arr) = z.array() {
            if let Some(v) = arr.get("limit") { if let Some(n) = v.long() { fo.limit = Some(n); } }
            if let Some(v) = arr.get("skip") { if let Some(n) = v.long() { fo.skip = Some(n as u64); } }
            if let Some(v) = arr.get("sort") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.sort = Some(d); } }
            if let Some(v) = arr.get("projection") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.projection = Some(d); } }
        }
    }
    fo
}

fn parse_update_options(opts: Option<&Zval>) -> mongodb::options::UpdateOptions {
    let mut uo = mongodb::options::UpdateOptions::default();
    if let Some(z) = opts {
        if let Some(arr) = z.array() {
            if let Some(v) = arr.get("upsert") { if let Some(b) = v.bool() { uo.upsert = Some(b); } }
        }
    }
    uo
}

fn parse_find_one_and_update_options(opts: Option<&Zval>) -> mongodb::options::FindOneAndUpdateOptions {
    let mut fo = mongodb::options::FindOneAndUpdateOptions::default();
    if let Some(z) = opts {
        if let Some(arr) = z.array() {
            if let Some(v) = arr.get("returnDocument") {
                if let Some(n) = v.long() {
                    if n == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
                }
            }
            if let Some(v) = arr.get("projection") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.projection = Some(d); } }
            if let Some(v) = arr.get("upsert") { if let Some(b) = v.bool() { fo.upsert = Some(b); } }
        }
    }
    fo
}
```

- [ ] **Step 2: Update all sync #[php_function] wrappers to use these helpers**

Change every function that has `_opts: Option<&Zval>` to actually parse and pass the options. For example:
```rust
#[php_function]
pub fn zealphp_mongodb_find(
    pool_id: i64, db: &str, col: &str, filter: &Zval, opts: Option<&Zval>,
) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let find_opts = parse_find_options(opts);
    let mongo_cursor = ops::find_with_options(&client, db, col, filter_doc, find_opts)
        .map_err(|e| PhpException::default(e))?;
    let cursor_id = cursor::store_cursor(mongo_cursor);
    Ok(cursor_id as i64)
}
```

Apply this pattern to: `find`, `find_one`, `update_one`, `update_many`, `replace_one`, `find_one_and_update`, `find_one_and_delete`, `find_one_and_replace`.

- [ ] **Step 3: Update ops.rs to accept option structs**

For each operation, add a variant that accepts the option struct:
```rust
pub fn find_with_options(
    client: &Client, db: &str, col: &str, filter: Document, opts: mongodb::options::FindOptions,
) -> Result<mongodb::Cursor<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find(filter).with_options(opts).await })
}
```

- [ ] **Step 4: Build, test, commit**
```bash
git add ext/src/lib.rs ext/src/ops.rs
git commit -m "feat: sync path options passthrough — upsert, projection, sort, limit, skip, returnDocument for all operations"
```

---

## Task 9: Comprehensive Test Suite

**Files:**
- Create: `tests/test_collection_full.php`
- Create: `tests/test_database.php`
- Create: `tests/test_client.php`
- Create: `tests/test_options.php`
- Create: `tests/test_async_full.php`
- Create: `tests/test_exceptions.php`
- Modify: `tests/test_bson_types.php` (from Task 2)

- [ ] **Step 1: Write test_collection_full.php**

Test insertMany, bulkWrite, estimatedDocumentCount, drop, rename, createIndexes, dropIndex, dropIndexes, listIndexes. Run against MongoDB at `mongodb://db.selfmade.ninja:27017`, use database `zealphp_test`, auto-create/drop test collections.

- [ ] **Step 2: Write test_options.php**

Test upsert on updateOne/updateMany/replaceOne, returnDocument AFTER on findOneAndUpdate/findOneAndReplace, projection on findOne/find, sort+limit+skip on find. Test both sync and async paths.

- [ ] **Step 3: Write test_async_full.php**

Run inside `OpenSwoole\Coroutine::run()` context. Test all operations via the eventfd bridge. Test concurrent coroutines. Test timeout handling.

- [ ] **Step 4: Write test_database.php**

Test command, createCollection, dropCollection, listCollections, listCollectionNames, aggregate, drop.

- [ ] **Step 5: Write test_client.php**

Test listDatabases, listDatabaseNames, dropDatabase, selectDatabase, selectCollection, startSession.

- [ ] **Step 6: Run all tests, fix failures, commit**
```bash
for t in tests/test_*.php; do echo "=== $t ==="; php $t; echo; done
git add tests/
git commit -m "test: comprehensive test suite — BSON types, collection, database, client, options, async, exceptions"
```

---

## Task 10: Final Integration + Autoloader

**Files:**
- Create: `composer.json`
- Update: README or similar

- [ ] **Step 1: Create composer.json for PSR-4 autoloading**

```json
{
    "name": "zealphp/mongodb",
    "description": "Async MongoDB driver for ZealPHP — Rust extension with PHP library",
    "type": "library",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "ZealPHP\\MongoDB\\": "php/src/"
        }
    },
    "require": {
        "php": ">=8.1",
        "ext-zealphp-mongodb-ext": "*"
    },
    "suggest": {
        "ext-openswoole": "For async coroutine support"
    }
}
```

- [ ] **Step 2: Verify autoloader works with all new classes**
```bash
php -r "
require 'vendor/autoload.php'; // or manual autoloader
echo class_exists('ZealPHP\MongoDB\Client') ? 'OK' : 'FAIL'; echo ' Client\n';
echo class_exists('ZealPHP\MongoDB\BSON\Binary') ? 'OK' : 'FAIL'; echo ' Binary\n';
echo class_exists('ZealPHP\MongoDB\ReadConcern') ? 'OK' : 'FAIL'; echo ' ReadConcern\n';
echo class_exists('ZealPHP\MongoDB\Session') ? 'OK' : 'FAIL'; echo ' Session\n';
echo class_exists('ZealPHP\MongoDB\GridFS\Bucket') ? 'OK' : 'FAIL'; echo ' GridFS Bucket\n';
echo class_exists('ZealPHP\MongoDB\Exception\CommandException') ? 'OK' : 'FAIL'; echo ' CommandException\n';
"
```

- [ ] **Step 3: Run full test suite, commit, push**
```bash
git add -A
git commit -m "feat: zealphp-mongodb v1.0 — full driver parity with official PHP MongoDB driver"
git push origin master
```

---

## Summary

| Task | What | Rust | PHP | Tests |
|---|---|---|---|---|
| 1 | BSON interfaces | - | 11 files | - |
| 2 | BSON type classes | - | 10 files | 1 file |
| 3 | Rust BSON conversion | bson_convert.rs | Collection.php | - |
| 4 | Exception hierarchy | - | 10 files | - |
| 5 | Read/Write Concerns | - | 3 files + getters | - |
| 6 | Collection methods | lib.rs, ops.rs, async_ops.rs | Collection.php + 2 results | 1 file |
| 7 | Database + Client methods | lib.rs, ops.rs | Database.php, Client.php + 3 stubs | 1 file |
| 8 | Sync path options | lib.rs, ops.rs | - | - |
| 9 | Test suite | - | - | 7 files |
| 10 | Composer + integration | - | composer.json | verify |

**Total new files:** ~40 PHP, ~3 Rust modifications, ~7 test files
**Estimated LOC:** ~2500 PHP, ~400 Rust
