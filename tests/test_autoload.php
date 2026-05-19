<?php

declare(strict_types=1);

use ZealPHP\MongoDB\ArrayCursor;
use ZealPHP\MongoDB\AsyncBridge;
use ZealPHP\MongoDB\BSON\Binary;
use ZealPHP\MongoDB\BSON\Decimal128;
use ZealPHP\MongoDB\BSON\Int64;
use ZealPHP\MongoDB\BSON\Javascript;
use ZealPHP\MongoDB\BSON\MaxKey;
use ZealPHP\MongoDB\BSON\MinKey;
use ZealPHP\MongoDB\BSON\ObjectId;
use ZealPHP\MongoDB\BSON\PackedArray;
use ZealPHP\MongoDB\BSON\Persistable;
use ZealPHP\MongoDB\BSON\Regex;
use ZealPHP\MongoDB\BSON\Serializable;
use ZealPHP\MongoDB\BSON\Timestamp;
use ZealPHP\MongoDB\BSON\Type;
use ZealPHP\MongoDB\BSON\Unserializable;
use ZealPHP\MongoDB\BSON\UTCDateTime;
use ZealPHP\MongoDB\BulkWriteResult;
use ZealPHP\MongoDB\ChangeStream;
use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Cursor;
use ZealPHP\MongoDB\Database;
use ZealPHP\MongoDB\DeleteResult;
use ZealPHP\MongoDB\Document;
use ZealPHP\MongoDB\Exception\AuthenticationException;
use ZealPHP\MongoDB\Exception\BulkWriteException;
use ZealPHP\MongoDB\Exception\CommandException;
use ZealPHP\MongoDB\Exception\ConnectionException;
use ZealPHP\MongoDB\Exception\ConnectionTimeoutException;
use ZealPHP\MongoDB\Exception\Exception;
use ZealPHP\MongoDB\Exception\ExecutionTimeoutException;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;
use ZealPHP\MongoDB\Exception\LogicException;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\Exception\ServerException;
use ZealPHP\MongoDB\Exception\UnexpectedValueException;
use ZealPHP\MongoDB\GridFS\Bucket;
use ZealPHP\MongoDB\InsertManyResult;
use ZealPHP\MongoDB\InsertOneResult;
use ZealPHP\MongoDB\Operation\FindOneAndReplace;
use ZealPHP\MongoDB\Operation\FindOneAndUpdate;
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\Session;
use ZealPHP\MongoDB\UpdateResult;
use ZealPHP\MongoDB\WriteConcern;

// tests/test_autoload.php
spl_autoload_register(static function ($class) {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (! file_exists($file)) {
        return;
    }

    require $file;
});

$classes = [
    Client::class,
    Database::class,
    Collection::class,
    Document::class,
    Cursor::class,
    ArrayCursor::class,
    AsyncBridge::class,
    InsertOneResult::class,
    InsertManyResult::class,
    UpdateResult::class,
    DeleteResult::class,
    BulkWriteResult::class,
    ReadConcern::class,
    WriteConcern::class,
    ReadPreference::class,
    Session::class,
    ChangeStream::class,
    ObjectId::class,
    UTCDateTime::class,
    Regex::class,
    Binary::class,
    Decimal128::class,
    Int64::class,
    Timestamp::class,
    Javascript::class,
    MinKey::class,
    MaxKey::class,
    \ZealPHP\MongoDB\BSON\Document::class,
    PackedArray::class,
    Type::class,
    Serializable::class,
    Unserializable::class,
    Persistable::class,
    Exception::class,
    RuntimeException::class,
    ConnectionException::class,
    AuthenticationException::class,
    ConnectionTimeoutException::class,
    ServerException::class,
    CommandException::class,
    ExecutionTimeoutException::class,
    BulkWriteException::class,
    LogicException::class,
    InvalidArgumentException::class,
    UnexpectedValueException::class,
    Bucket::class,
    FindOneAndUpdate::class,
    FindOneAndReplace::class,
];

$pass = 0;
$fail = 0;
foreach ($classes as $class) {
    if (class_exists($class) || interface_exists($class)) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $class not found\n";
    }
}

echo "\nAutoload: $pass/" . count($classes) . ' classes loaded';
if ($fail > 0) {
    echo ", $fail FAILED";
}

echo "\n";
