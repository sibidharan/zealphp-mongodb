<?php
// tests/test_autoload.php
spl_autoload_register(function($class) {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

$classes = [
    'ZealPHP\\MongoDB\\Client',
    'ZealPHP\\MongoDB\\Database',
    'ZealPHP\\MongoDB\\Collection',
    'ZealPHP\\MongoDB\\Document',
    'ZealPHP\\MongoDB\\Cursor',
    'ZealPHP\\MongoDB\\ArrayCursor',
    'ZealPHP\\MongoDB\\AsyncBridge',
    'ZealPHP\\MongoDB\\InsertOneResult',
    'ZealPHP\\MongoDB\\InsertManyResult',
    'ZealPHP\\MongoDB\\UpdateResult',
    'ZealPHP\\MongoDB\\DeleteResult',
    'ZealPHP\\MongoDB\\BulkWriteResult',
    'ZealPHP\\MongoDB\\ReadConcern',
    'ZealPHP\\MongoDB\\WriteConcern',
    'ZealPHP\\MongoDB\\ReadPreference',
    'ZealPHP\\MongoDB\\Session',
    'ZealPHP\\MongoDB\\ChangeStream',
    'ZealPHP\\MongoDB\\BSON\\ObjectId',
    'ZealPHP\\MongoDB\\BSON\\UTCDateTime',
    'ZealPHP\\MongoDB\\BSON\\Regex',
    'ZealPHP\\MongoDB\\BSON\\Binary',
    'ZealPHP\\MongoDB\\BSON\\Decimal128',
    'ZealPHP\\MongoDB\\BSON\\Int64',
    'ZealPHP\\MongoDB\\BSON\\Timestamp',
    'ZealPHP\\MongoDB\\BSON\\Javascript',
    'ZealPHP\\MongoDB\\BSON\\MinKey',
    'ZealPHP\\MongoDB\\BSON\\MaxKey',
    'ZealPHP\\MongoDB\\BSON\\Document',
    'ZealPHP\\MongoDB\\BSON\\PackedArray',
    'ZealPHP\\MongoDB\\BSON\\Type',
    'ZealPHP\\MongoDB\\BSON\\Serializable',
    'ZealPHP\\MongoDB\\BSON\\Unserializable',
    'ZealPHP\\MongoDB\\BSON\\Persistable',
    'ZealPHP\\MongoDB\\Exception\\Exception',
    'ZealPHP\\MongoDB\\Exception\\RuntimeException',
    'ZealPHP\\MongoDB\\Exception\\ConnectionException',
    'ZealPHP\\MongoDB\\Exception\\AuthenticationException',
    'ZealPHP\\MongoDB\\Exception\\ConnectionTimeoutException',
    'ZealPHP\\MongoDB\\Exception\\ServerException',
    'ZealPHP\\MongoDB\\Exception\\CommandException',
    'ZealPHP\\MongoDB\\Exception\\ExecutionTimeoutException',
    'ZealPHP\\MongoDB\\Exception\\BulkWriteException',
    'ZealPHP\\MongoDB\\Exception\\LogicException',
    'ZealPHP\\MongoDB\\Exception\\InvalidArgumentException',
    'ZealPHP\\MongoDB\\Exception\\UnexpectedValueException',
    'ZealPHP\\MongoDB\\GridFS\\Bucket',
    'ZealPHP\\MongoDB\\Operation\\FindOneAndUpdate',
    'ZealPHP\\MongoDB\\Operation\\FindOneAndReplace',
];

$pass = 0; $fail = 0;
foreach ($classes as $class) {
    if (class_exists($class) || interface_exists($class)) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $class not found\n";
    }
}
echo "\nAutoload: $pass/" . count($classes) . " classes loaded";
if ($fail > 0) echo ", $fail FAILED";
echo "\n";
