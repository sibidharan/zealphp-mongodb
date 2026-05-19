<?php
/**
 * Collection Full API Tests
 *
 * Tests insertMany, bulkWrite, estimatedDocumentCount, index operations,
 * drop, count, withOptions, findOneAndUpdate, findOneAndDelete, findOneAndReplace
 * against a live MongoDB instance.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require_once $file;
});

use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\InsertManyResult;
use ZealPHP\MongoDB\BulkWriteResult;

$pass = 0; $fail = 0; $errors = [];
function check($label, $cond) {
    global $pass, $fail, $errors;
    if ($cond) { $pass++; }
    else { $fail++; $errors[] = $label; echo "FAIL $label\n"; }
}

$client = new Client('mongodb://db.selfmade.ninja:27017');
$db = $client->selectDatabase('zealphp_test');
$col = $db->selectCollection('test_collection_full');

// Clean up from any previous run
$col->deleteMany([]);

// ============================================================
echo "=== insertMany ===\n";
// ============================================================

$docs = [
    ['name' => 'Alice', 'age' => 30, 'city' => 'NYC'],
    ['name' => 'Bob', 'age' => 25, 'city' => 'LA'],
    ['name' => 'Charlie', 'age' => 35, 'city' => 'NYC'],
];
$result = $col->insertMany($docs);

check('insertMany returns InsertManyResult', $result instanceof InsertManyResult);
check('insertMany inserted count is 3', $result->getInsertedCount() === 3);
check('insertMany returns 3 IDs', count($result->getInsertedIds()) === 3);
check('insertMany is acknowledged', $result->isAcknowledged());

$count = $col->countDocuments([]);
check('insertMany: countDocuments confirms 3 docs', $count === 3);

// ============================================================
echo "\n=== bulkWrite ===\n";
// ============================================================

$bulkResult = $col->bulkWrite([
    ['insertOne' => [['name' => 'Diana', 'age' => 28, 'city' => 'Chicago']]],
    ['updateOne' => [['name' => 'Alice'], ['$set' => ['age' => 31]]]],
    ['deleteOne' => [['name' => 'Bob']]],
]);

check('bulkWrite returns BulkWriteResult', $bulkResult instanceof BulkWriteResult);
check('bulkWrite inserted 1', $bulkResult->getInsertedCount() === 1);
check('bulkWrite matched 1', $bulkResult->getMatchedCount() === 1);
check('bulkWrite modified 1', $bulkResult->getModifiedCount() === 1);
check('bulkWrite deleted 1', $bulkResult->getDeletedCount() === 1);
check('bulkWrite is acknowledged', $bulkResult->isAcknowledged());

$count = $col->countDocuments([]);
check('bulkWrite: net count is 3 (was 3, +1 insert, -1 delete)', $count === 3);

// Verify Alice was updated
$alice = $col->findOne(['name' => 'Alice']);
check('bulkWrite: Alice age updated to 31', ($alice['age'] ?? null) === 31);

// ============================================================
echo "\n=== estimatedDocumentCount ===\n";
// ============================================================

$est = $col->estimatedDocumentCount();
check('estimatedDocumentCount returns int', is_int($est));
check('estimatedDocumentCount >= 0', $est >= 0);

// ============================================================
echo "\n=== createIndex / createIndexes / listIndexes / dropIndex / dropIndexes ===\n";
// ============================================================

// createIndex
$idxName = $col->createIndex(['name' => 1]);
check('createIndex returns string name', is_string($idxName) && strlen($idxName) > 0);

// createIndexes
$idxNames = $col->createIndexes([
    ['key' => ['age' => 1]],
    ['key' => ['city' => 1]],
]);
check('createIndexes returns array', is_array($idxNames));
check('createIndexes created 2 indexes', count($idxNames) === 2);

// listIndexes
$indexes = $col->listIndexes();
check('listIndexes returns array', is_array($indexes));
// Should have at least _id + name + age + city = 4
$indexCount = count($indexes);
check('listIndexes has >= 4 indexes', $indexCount >= 4);

// dropIndex (drop the name index)
$dropResult = $col->dropIndex($idxName);
check('dropIndex returns ok', ($dropResult['ok'] ?? 0) == 1);

$indexesAfterDrop = $col->listIndexes();
check('listIndexes after dropIndex has fewer indexes', count($indexesAfterDrop) < $indexCount);

// dropIndexes (drops all non-_id indexes)
$dropAllResult = $col->dropIndexes();
check('dropIndexes returns ok', ($dropAllResult['ok'] ?? 0) == 1);

$indexesAfterDropAll = $col->listIndexes();
check('after dropIndexes, only _id index remains', count($indexesAfterDropAll) === 1);

// ============================================================
echo "\n=== drop ===\n";
// ============================================================

// Create a temporary collection, verify it exists, drop it, verify it's gone
$tmpCol = $db->selectCollection('test_collection_drop_tmp');
$tmpCol->insertOne(['marker' => true]);

$names = $db->listCollectionNames();
check('temp collection exists before drop', in_array('test_collection_drop_tmp', $names));

$tmpCol->drop();

$namesAfter = $db->listCollectionNames();
check('temp collection gone after drop', !in_array('test_collection_drop_tmp', $namesAfter));

// ============================================================
echo "\n=== count (alias for countDocuments) ===\n";
// ============================================================

$countAlias = $col->count([]);
$countReal = $col->countDocuments([]);
check('count returns same as countDocuments', $countAlias === $countReal);
check('count returns int', is_int($countAlias));

// ============================================================
echo "\n=== withOptions ===\n";
// ============================================================

$col2 = $col->withOptions(['readConcern' => 'majority']);
check('withOptions returns Collection instance', $col2 instanceof Collection);
check('withOptions returns new instance', $col2 !== $col);
check('withOptions preserves collection name', $col2->getCollectionName() === $col->getCollectionName());
check('withOptions preserves database name', $col2->getDatabaseName() === $col->getDatabaseName());

// ============================================================
echo "\n=== findOneAndUpdate with returnDocument AFTER ===\n";
// ============================================================

// Make sure we have known data
$col->deleteMany([]);
$col->insertOne(['name' => 'Eve', 'score' => 10]);

$updated = $col->findOneAndUpdate(
    ['name' => 'Eve'],
    ['$set' => ['score' => 99]],
    ['returnDocument' => 2]  // RETURN_DOCUMENT_AFTER
);
check('findOneAndUpdate returns a doc', $updated !== null);
check('findOneAndUpdate AFTER has updated score', ($updated['score'] ?? null) === 99);
check('findOneAndUpdate AFTER has name', ($updated['name'] ?? null) === 'Eve');

// ============================================================
echo "\n=== findOneAndDelete ===\n";
// ============================================================

$col->insertOne(['name' => 'Frank', 'temp' => true]);

$deleted = $col->findOneAndDelete(['name' => 'Frank']);
check('findOneAndDelete returns deleted doc', $deleted !== null);
check('findOneAndDelete doc has name', ($deleted['name'] ?? null) === 'Frank');

$frank = $col->findOne(['name' => 'Frank']);
check('findOneAndDelete: doc is gone from collection', $frank === null);

// ============================================================
echo "\n=== findOneAndReplace ===\n";
// ============================================================

$col->insertOne(['name' => 'Grace', 'old_field' => 'yes']);

$replaced = $col->findOneAndReplace(
    ['name' => 'Grace'],
    ['name' => 'Grace', 'new_field' => 'replaced'],
    ['returnDocument' => 2]  // RETURN_DOCUMENT_AFTER
);
check('findOneAndReplace returns a doc', $replaced !== null);
check('findOneAndReplace AFTER has new_field', ($replaced['new_field'] ?? null) === 'replaced');

// Verify old_field is gone (full replace, not merge)
$grace = $col->findOne(['name' => 'Grace']);
check('findOneAndReplace: old_field is gone', !isset($grace['old_field']));
check('findOneAndReplace: new_field present', ($grace['new_field'] ?? null) === 'replaced');

// ============================================================
// Cleanup
// ============================================================
$col->drop();

echo "\n========================================\n";
echo "Results: $pass passed, $fail failed\n";
echo "========================================\n";
if (count($errors) > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) echo "  - $e\n";
}
exit($fail > 0 ? 1 : 0);
