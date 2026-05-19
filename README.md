# zealphp-mongodb

[![Tests](https://github.com/sibidharan/zealphp-mongodb/actions/workflows/tests.yml/badge.svg)](https://github.com/sibidharan/zealphp-mongodb/actions/workflows/tests.yml)
[![Coding Standards](https://github.com/sibidharan/zealphp-mongodb/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/sibidharan/zealphp-mongodb/actions/workflows/coding-standards.yml)
[![Static Analysis](https://github.com/sibidharan/zealphp-mongodb/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/sibidharan/zealphp-mongodb/actions/workflows/static-analysis.yml)

Async MongoDB driver for PHP — a Rust extension bridging the official [mongo-rust-driver](https://github.com/mongodb/mongo-rust-driver) into PHP via [ext-php-rs](https://github.com/davidcole1340/ext-php-rs), with non-blocking coroutine support through [OpenSwoole](https://openswoole.com/).

Drop-in replacement for [`mongodb/mongodb`](https://github.com/mongodb/mongo-php-library) with the same Collection, Database, Client, and BSON APIs.

## Features

- **Full API parity** with the official PHP MongoDB library — Collection (25 methods), Database (15 methods), Client (12 methods)
- **Non-blocking async** via eventfd + OpenSwoole `Event::add` + `Channel` — zero thread blocking in coroutine mode
- **Complete BSON type system** — ObjectId, UTCDateTime, Regex, Binary, Decimal128, Int64, Timestamp, Javascript, MinKey, MaxKey, Document, PackedArray
- **All query options** — upsert, returnDocument, projection, sort, limit, skip on both sync and async paths
- **Rust performance** — backed by the official MongoDB Rust driver with tokio async runtime
- **Connection pooling** — persistent connections across requests, managed by the Rust extension
- **Dual-mode operation** — sync (block_on) without OpenSwoole, async (eventfd) with OpenSwoole coroutines

## Requirements

- PHP >= 8.1
- Rust toolchain (for building the extension)
- MongoDB server 5.0+
- OpenSwoole (optional, for async coroutine mode)

## Installation

### Build the Rust extension

```bash
# Prerequisites
sudo apt-get install -y php-dev libclang-dev   # PHP headers + libclang for bindgen
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh  # Rust 1.88+

# Build
cd ext
cargo build --release

# Install
sudo cp target/release/libzealphp_mongodb.so $(php -r "echo ini_get('extension_dir');")/zealphp_mongodb.so
echo "extension=zealphp_mongodb.so" | sudo tee $(php --ini | grep "Scan for" | cut -d: -f2 | tr -d ' ')/99-zealphp-mongodb.ini

# Verify
php -r "echo zealphp_mongodb_version();"  # should print 0.2.0
```

### Docker

The extension builds automatically in Docker — see the [labs-devops Dockerfile](https://github.com/sibidharan/labs-devops) for reference:

```dockerfile
COPY --from=rust:latest /usr/local/cargo /usr/local/cargo
COPY --from=rust:latest /usr/local/rustup /usr/local/rustup
ENV PATH="/usr/local/cargo/bin:${PATH}"

RUN apt-get install -y libclang-dev && \
    git clone https://github.com/sibidharan/zealphp-mongodb.git /tmp/zealphp-mongodb && \
    cd /tmp/zealphp-mongodb/ext && cargo build --release && \
    cp target/release/libzealphp_mongodb.so $(php -r "echo ini_get('extension_dir');")/zealphp_mongodb.so && \
    rm -rf /tmp/zealphp-mongodb/ext/target
```

### Install the PHP library

```bash
composer require zealphp/mongodb
```

## Quick Start

```php
use ZealPHP\MongoDB\Client;

$client = new Client('mongodb://localhost:27017');
$db = $client->selectDatabase('myapp');
$users = $db->selectCollection('users');

// Insert
$result = $users->insertOne(['name' => 'Alice', 'age' => 30]);
echo $result->getInsertedId();

// Find
$user = $users->findOne(['name' => 'Alice']);
echo $user->name; // Property access (Document extends ArrayObject)
echo $user['age']; // Array access also works

// Find with options
$cursor = $users->find(
    ['age' => ['$gt' => 18]],
    ['sort' => ['age' => -1], 'limit' => 10, 'projection' => ['name' => 1]]
);
foreach ($cursor as $doc) {
    echo $doc->name . "\n";
}

// Update with upsert
$users->updateOne(
    ['email' => 'bob@example.com'],
    ['$set' => ['name' => 'Bob', 'age' => 25]],
    ['upsert' => true]
);

// Aggregate
$pipeline = [
    ['$group' => ['_id' => '$department', 'avg_age' => ['$avg' => '$age']]],
    ['$sort' => ['avg_age' => -1]],
];
foreach ($users->aggregate($pipeline) as $doc) {
    echo "{$doc->_id}: {$doc->avg_age}\n";
}

// BSON types
use ZealPHP\MongoDB\BSON\ObjectId;
use ZealPHP\MongoDB\BSON\UTCDateTime;
use ZealPHP\MongoDB\BSON\Regex;

$users->insertOne([
    '_id' => new ObjectId(),
    'created_at' => new UTCDateTime(),
    'username' => new Regex('^admin', 'i'),
]);
```

## Async Mode (OpenSwoole)

When running inside an OpenSwoole coroutine, all MongoDB operations automatically become non-blocking:

```php
use OpenSwoole\Runtime;
use OpenSwoole\Coroutine;
use ZealPHP\MongoDB\Client;

Runtime::enableCoroutine(OPENSWOOLE_HOOK_ALL);

// Connect BEFORE coroutine context (pool::connect uses block_on)
$client = new Client('mongodb://localhost:27017');

Coroutine::run(function() use ($client) {
    $db = $client->selectDatabase('myapp');
    $col = $db->users;

    // This yields the coroutine via eventfd — other coroutines run while waiting
    $user = $col->findOne(['name' => 'Alice']);

    // Concurrent MongoDB operations
    $chan = new Coroutine\Channel(3);
    for ($i = 0; $i < 3; $i++) {
        Coroutine::create(function() use ($col, $i, $chan) {
            $count = $col->countDocuments(['department' => "dept_$i"]);
            $chan->push("dept_$i: $count");
        });
    }
    for ($i = 0; $i < 3; $i++) {
        echo $chan->pop() . "\n";
    }
});
```

## API Reference

### Collection Methods

| Method | Description |
|--------|-------------|
| `findOne($filter, $options)` | Find a single document |
| `find($filter, $options)` | Find documents (returns Cursor) |
| `insertOne($document)` | Insert a single document |
| `insertMany($documents)` | Insert multiple documents |
| `updateOne($filter, $update, $options)` | Update a single document |
| `updateMany($filter, $update, $options)` | Update multiple documents |
| `deleteOne($filter)` | Delete a single document |
| `deleteMany($filter)` | Delete multiple documents |
| `replaceOne($filter, $replacement, $options)` | Replace a single document |
| `countDocuments($filter)` | Count documents matching filter |
| `estimatedDocumentCount()` | Fast approximate count |
| `distinct($field, $filter)` | Get distinct values |
| `aggregate($pipeline)` | Run aggregation pipeline |
| `findOneAndUpdate($filter, $update, $options)` | Find and update atomically |
| `findOneAndDelete($filter)` | Find and delete atomically |
| `findOneAndReplace($filter, $replacement, $options)` | Find and replace atomically |
| `bulkWrite($operations)` | Execute bulk operations |
| `createIndex($keys, $options)` | Create an index |
| `createIndexes($indexes)` | Create multiple indexes |
| `listIndexes()` | List collection indexes |
| `dropIndex($name)` | Drop an index |
| `dropIndexes()` | Drop all indexes |
| `drop()` | Drop the collection |
| `rename($newName)` | Rename the collection |
| `count($filter)` | Alias for countDocuments |

### Database Methods

| Method | Description |
|--------|-------------|
| `command($command)` | Run a database command |
| `aggregate($pipeline)` | Database-level aggregation |
| `createCollection($name)` | Create a collection |
| `dropCollection($name)` | Drop a collection |
| `drop()` | Drop the database |
| `listCollections()` | List collections |
| `listCollectionNames()` | List collection names |
| `selectCollection($name)` | Get a Collection instance |
| `selectGridFSBucket()` | Get a GridFS Bucket (stub) |

### BSON Types

| Type | Extended JSON |
|------|--------------|
| `ObjectId` | `{"$oid": "..."}` |
| `UTCDateTime` | `{"$date": {"$numberLong": "..."}}` |
| `Regex` | `{"$regularExpression": {"pattern": "...", "options": "..."}}` |
| `Binary` | `{"$binary": {"base64": "...", "subType": "..."}}` |
| `Decimal128` | `{"$numberDecimal": "..."}` |
| `Int64` | Native int |
| `Timestamp` | `{"$timestamp": {"t": ..., "i": ...}}` |
| `Javascript` | `{"$code": "..."}` |
| `MinKey` | `{"$minKey": 1}` |
| `MaxKey` | `{"$maxKey": 1}` |

## Architecture

```
┌─────────────────────────────────────────────────┐
│                  PHP Application                │
│  Collection / Database / Client / BSON types    │
├─────────────────────────────────────────────────┤
│              AsyncBridge (PHP)                  │
│  isCoroutineMode() → eventfd path or sync path  │
├──────────────────────┬──────────────────────────┤
│   Sync Path          │     Async Path           │
│   block_on(future)   │  spawn_task → eventfd    │
│                      │  Event::add → Channel    │
├──────────────────────┴──────────────────────────┤
│           Rust Extension (ext-php-rs)           │
│  34 PHP functions · BSON conversion · Pool      │
├─────────────────────────────────────────────────┤
│        mongo-rust-driver + tokio runtime        │
│          Connection pooling · TLS · Auth        │
├─────────────────────────────────────────────────┤
│              MongoDB Server                     │
└─────────────────────────────────────────────────┘
```

## Development

```bash
# Install dev dependencies
composer install

# Run unit tests (no MongoDB needed)
vendor/bin/phpunit --testsuite Unit

# Run integration tests (requires MongoDB + ext)
MONGODB_URI=mongodb://localhost:27017 vendor/bin/phpunit --testsuite Integration

# Check coding standards
vendor/bin/phpcs

# Auto-fix coding standards
vendor/bin/phpcbf

# Static analysis
vendor/bin/psalm

# PHP modernization check
vendor/bin/rector --dry-run
```

## License

MIT
