# zealphp-mongodb

Async MongoDB driver for PHP / OpenSwoole.

Bridges the official Rust MongoDB driver (mongo-rust-driver/tokio) into
OpenSwoole's coroutine system. Drop-in replacement for `mongodb/mongodb`.

## Build

```bash
make build    # Compile Rust extension
make install  # Install .so + php.ini  
make test-ext # Verify extension loads
```

## Usage

```php
use ZealPHP\MongoDB\Client;

$client = new Client('mongodb://localhost:27017');
$db = $client->selectDatabase('mydb');
$user = $db->users->findOne(['email' => 'test@test.com']);
```
