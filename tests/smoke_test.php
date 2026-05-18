<?php
// Load PHP library via manual requires (no composer autoload yet)
$base = __DIR__ . '/../php/src';
require_once "$base/Client.php";
require_once "$base/Database.php";
require_once "$base/Collection.php";
require_once "$base/Cursor.php";
require_once "$base/InsertOneResult.php";
require_once "$base/UpdateResult.php";
require_once "$base/DeleteResult.php";

use ZealPHP\MongoDB\Client;

$uri = $argv[1] ?? 'mongodb://db.selfmade.ninja:27017';
$passed = 0; $failed = 0;

function assert_eq($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) { echo "  ✓ $label\n"; $passed++; }
    else { echo "  ✗ $label: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; $failed++; }
}
function assert_true($label, $val) { assert_eq($label, true, $val); }

echo "=== zealphp-mongodb T0 Smoke Test ===\n\n";

// Connection
echo "Connection:\n";
$client = new Client($uri);
assert_true("Client created", $client->getPoolId() > 0);
$db = $client->selectDatabase('zealphp_test');
assert_eq("Database name", 'zealphp_test', $db->getDatabaseName());
$col = $db->selectCollection('smoke_test');
assert_eq("Namespace", 'zealphp_test.smoke_test', $col->getNamespace());
// Also test magic __get
$col2 = $db->smoke_test;
assert_eq("Magic __get namespace", 'zealphp_test.smoke_test', $col2->getNamespace());

// Clean up first
$col->deleteOne(['name' => 'Alice']);
$col->deleteOne(['name' => 'Bob']);

// Insert
echo "\nInsertOne:\n";
$result = $col->insertOne(['name' => 'Alice', 'age' => 30]);
assert_true("Acknowledged", $result->isAcknowledged());
assert_eq("Insert count", 1, $result->getInsertedCount());
assert_true("Has ID", $result->getInsertedId() !== null);

// FindOne
echo "\nFindOne:\n";
$alice = $col->findOne(['name' => 'Alice']);
assert_true("Found", $alice !== null);
assert_eq("Name", 'Alice', $alice['name'] ?? null);
assert_eq("Age", 30, $alice['age'] ?? null);

// FindOne not found
$nobody = $col->findOne(['name' => 'Nobody12345']);
assert_eq("Not found returns null", null, $nobody);

// CountDocuments
echo "\nCountDocuments:\n";
$count = $col->countDocuments(['name' => 'Alice']);
assert_true("Count >= 1", $count >= 1);

// UpdateOne
echo "\nUpdateOne:\n";
$upd = $col->updateOne(['name' => 'Alice'], ['$set' => ['age' => 31]]);
assert_eq("Matched", 1, $upd->getMatchedCount());
assert_eq("Modified", 1, $upd->getModifiedCount());
$alice2 = $col->findOne(['name' => 'Alice']);
assert_eq("Age updated", 31, $alice2['age'] ?? null);

// Find (cursor)
echo "\nFind + Cursor:\n";
$col->insertOne(['name' => 'Bob', 'age' => 25]);
$cursor = $col->find(['age' => ['$gte' => 20]]);
$results = $cursor->toArray();
assert_true("Returns array", is_array($results));
assert_true("Count >= 2", count($results) >= 2);

// Iterator protocol
echo "\nIterator:\n";
$cursor2 = $col->find(['name' => ['$in' => ['Alice', 'Bob']]]);
$names = [];
foreach ($cursor2 as $doc) {
    $names[] = $doc['name'];
}
assert_true("Iterator found Alice", in_array('Alice', $names));
assert_true("Iterator found Bob", in_array('Bob', $names));

// Aggregate
echo "\nAggregate:\n";
$agg = $col->aggregate([
    ['$match' => ['age' => ['$gte' => 20]]],
    ['$group' => ['_id' => null, 'avg_age' => ['$avg' => '$age']]],
]);
$aggResults = $agg->toArray();
assert_true("Has results", count($aggResults) > 0);
assert_true("Has avg_age", isset($aggResults[0]['avg_age']));

// DeleteOne
echo "\nDeleteOne:\n";
$del = $col->deleteOne(['name' => 'Alice']);
assert_eq("Deleted", 1, $del->getDeletedCount());
$del2 = $col->deleteOne(['name' => 'Bob']);
assert_eq("Deleted Bob", 1, $del2->getDeletedCount());

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
