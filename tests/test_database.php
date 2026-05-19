<?php
/**
 * Database API Tests
 *
 * Tests command, createCollection, dropCollection, listCollectionNames,
 * listCollections, drop, aggregate, selectGridFSBucket, withOptions,
 * __toString, __debugInfo against live MongoDB.
 */

// Preload ExceptionInterface (defined in Exception.php, not its own file)
require_once __DIR__ . '/../php/src/Exception/Exception.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require_once $file;
});

use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Database;
use ZealPHP\MongoDB\GridFS\Bucket;

$pass = 0; $fail = 0; $errors = [];
function check($label, $cond) {
    global $pass, $fail, $errors;
    if ($cond) { $pass++; }
    else { $fail++; $errors[] = $label; echo "FAIL $label\n"; }
}

$client = new Client('mongodb://db.selfmade.ninja:27017');
$db = $client->selectDatabase('zealphp_test');

// ============================================================
echo "=== command (ping) ===\n";
// ============================================================

$pingResult = $db->command(['ping' => 1]);
check('ping returns array', is_array($pingResult));
check('ping ok is 1', ($pingResult['ok'] ?? 0) == 1);

// ============================================================
echo "\n=== createCollection / dropCollection ===\n";
// ============================================================

$db->createCollection('test_db_create_col');
$names = $db->listCollectionNames();
check('createCollection: collection appears in list', in_array('test_db_create_col', $names));

$db->dropCollection('test_db_create_col');
$namesAfter = $db->listCollectionNames();
check('dropCollection: collection gone from list', !in_array('test_db_create_col', $namesAfter));

// ============================================================
echo "\n=== listCollectionNames ===\n";
// ============================================================

// Create a couple of collections to verify listing
$db->createCollection('test_db_list_a');
$db->createCollection('test_db_list_b');

$names = $db->listCollectionNames();
check('listCollectionNames returns array', is_array($names));
check('listCollectionNames includes test_db_list_a', in_array('test_db_list_a', $names));
check('listCollectionNames includes test_db_list_b', in_array('test_db_list_b', $names));

// Cleanup
$db->dropCollection('test_db_list_a');
$db->dropCollection('test_db_list_b');

// ============================================================
echo "\n=== listCollections ===\n";
// ============================================================

$db->createCollection('test_db_listfull');
$collections = $db->listCollections();
check('listCollections returns array', is_array($collections));

// Each entry should have 'name' field
$found = false;
foreach ($collections as $c) {
    $cName = is_array($c) ? ($c['name'] ?? null) : ($c->name ?? null);
    if ($cName === 'test_db_listfull') {
        $found = true;
        break;
    }
}
check('listCollections includes test_db_listfull', $found);

$db->dropCollection('test_db_listfull');

// ============================================================
echo "\n=== drop (database) ===\n";
// ============================================================

$tempDbName = 'zealphp_test_drop_tmp_' . time();
$tempDb = $client->selectDatabase($tempDbName);
// Create a collection to ensure the database is actually created
$tempDb->createCollection('marker');

// Verify it exists
$dbNames = $client->listDatabaseNames();
check('temp db exists before drop', in_array($tempDbName, $dbNames));

$tempDb->drop();

$dbNamesAfter = $client->listDatabaseNames();
check('temp db gone after drop', !in_array($tempDbName, $dbNamesAfter));

// ============================================================
echo "\n=== aggregate (database-level) ===\n";
// ============================================================

// Use $documents stage (MongoDB 5.1+) for database-level aggregate
$cursor = $db->aggregate([
    ['$documents' => [['x' => 1], ['x' => 2], ['x' => 3]]],
    ['$match' => ['x' => ['$gte' => 2]]],
]);
check('database aggregate returns ArrayCursor', $cursor instanceof \ZealPHP\MongoDB\ArrayCursor);
check('database aggregate is iterable', is_iterable($cursor));

$aggResults = [];
foreach ($cursor as $doc) {
    $aggResults[] = $doc;
}
check('database aggregate returned 2 docs (x>=2)', count($aggResults) === 2);

// ============================================================
echo "\n=== selectGridFSBucket ===\n";
// ============================================================

$bucket = $db->selectGridFSBucket();
check('selectGridFSBucket returns Bucket', $bucket instanceof Bucket);
check('bucket getBucketName is fs', $bucket->getBucketName() === 'fs');
check('bucket getDatabaseName matches', $bucket->getDatabaseName() === 'zealphp_test');

// Verify GridFS methods throw RuntimeException
$threw = false;
try {
    $bucket->openUploadStream('test.txt');
} catch (\ZealPHP\MongoDB\Exception\RuntimeException $e) {
    $threw = true;
}
check('bucket openUploadStream throws RuntimeException', $threw);

$threw = false;
try {
    $bucket->drop();
} catch (\ZealPHP\MongoDB\Exception\RuntimeException $e) {
    $threw = true;
}
check('bucket drop throws RuntimeException', $threw);

// Custom bucket name
$customBucket = $db->selectGridFSBucket(['bucketName' => 'media']);
check('custom bucket name is media', $customBucket->getBucketName() === 'media');

// ============================================================
echo "\n=== withOptions ===\n";
// ============================================================

$db2 = $db->withOptions(['readConcern' => 'majority']);
check('withOptions returns Database instance', $db2 instanceof Database);
check('withOptions returns new instance', $db2 !== $db);
check('withOptions preserves database name', $db2->getDatabaseName() === $db->getDatabaseName());

// ============================================================
echo "\n=== __toString ===\n";
// ============================================================

$str = (string)$db;
check('__toString returns database name', $str === 'zealphp_test');

// ============================================================
echo "\n=== __debugInfo ===\n";
// ============================================================

$debug = $db->__debugInfo();
check('__debugInfo returns array', is_array($debug));
check('__debugInfo has databaseName', ($debug['databaseName'] ?? null) === 'zealphp_test');
check('__debugInfo has poolId', array_key_exists('poolId', $debug));

echo "\n========================================\n";
echo "Results: $pass passed, $fail failed\n";
echo "========================================\n";
if (count($errors) > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) echo "  - $e\n";
}
exit($fail > 0 ? 1 : 0);
