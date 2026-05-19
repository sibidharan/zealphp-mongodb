<?php

declare(strict_types=1);

/**
 * Options Passthrough Tests
 *
 * Tests upsert, returnDocument, projection, sort, limit, skip options
 * on sync path against live MongoDB.
 */

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

use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\UpdateResult;

$pass = 0;
$fail = 0;
$errors = [];

function check($label, $cond)
{
    global $pass, $fail, $errors;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        $errors[] = $label;
        echo "FAIL $label\n";
    }
}

$client = new Client('mongodb://db.selfmade.ninja:27017');
$db = $client->selectDatabase('zealphp_test');
$col = $db->selectCollection('test_options');

// Clean slate
$col->deleteMany([]);

// ============================================================
echo "=== updateOne with upsert ===\n";
// ============================================================

$result = $col->updateOne(
    ['name' => 'UpsertOne'],
    ['$set' => ['name' => 'UpsertOne', 'value' => 42]],
    ['upsert' => true],
);
check('updateOne upsert returns UpdateResult', $result instanceof UpdateResult);
// Upserted count should be 1 for a new doc
check('updateOne upsert: matched 0 (new doc)', $result->getMatchedCount() === 0);

$doc = $col->findOne(['name' => 'UpsertOne']);
check('updateOne upsert: doc was created', $doc !== null);
check('updateOne upsert: doc has correct value', ($doc['value'] ?? null) === 42);

// ============================================================
echo "\n=== updateMany with upsert ===\n";
// ============================================================

// Cleanup first
$col->deleteMany(['group' => 'upsert_many']);

$result = $col->updateMany(
    ['group' => 'upsert_many'],
    ['$set' => ['group' => 'upsert_many', 'status' => 'active']],
    ['upsert' => true],
);
check('updateMany upsert returns UpdateResult', $result instanceof UpdateResult);

$doc = $col->findOne(['group' => 'upsert_many']);
check('updateMany upsert: doc was created', $doc !== null);
check('updateMany upsert: doc has correct status', ($doc['status'] ?? null) === 'active');

// ============================================================
echo "\n=== replaceOne with upsert ===\n";
// ============================================================

$result = $col->replaceOne(
    ['name' => 'ReplaceUpsert'],
    ['name' => 'ReplaceUpsert', 'replaced' => true, 'count' => 1],
    ['upsert' => true],
);
check('replaceOne upsert returns UpdateResult', $result instanceof UpdateResult);

$doc = $col->findOne(['name' => 'ReplaceUpsert']);
check('replaceOne upsert: doc was created', $doc !== null);
check('replaceOne upsert: doc has replaced flag', ($doc['replaced'] ?? null) === true);
check('replaceOne upsert: doc has count', ($doc['count'] ?? null) === 1);

// ============================================================
echo "\n=== findOneAndUpdate with returnDocument AFTER ===\n";
// ============================================================

$col->deleteMany(['name' => 'FindAndUp']);
$col->insertOne(['name' => 'FindAndUp', 'score' => 10]);

$updated = $col->findOneAndUpdate(
    ['name' => 'FindAndUp'],
    ['$set' => ['score' => 100]],
    ['returnDocument' => 2],  // RETURN_DOCUMENT_AFTER
);
check('findOneAndUpdate AFTER returns doc', $updated !== null);
check('findOneAndUpdate AFTER: score is 100', ($updated['score'] ?? null) === 100);
check('findOneAndUpdate AFTER: name preserved', ($updated['name'] ?? null) === 'FindAndUp');

// ============================================================
echo "\n=== findOneAndReplace with returnDocument AFTER ===\n";
// ============================================================

$col->deleteMany(['name' => 'FindAndRepl']);
$col->insertOne(['name' => 'FindAndRepl', 'old' => true]);

$replaced = $col->findOneAndReplace(
    ['name' => 'FindAndRepl'],
    ['name' => 'FindAndRepl', 'new' => true, 'version' => 2],
    ['returnDocument' => 2],  // RETURN_DOCUMENT_AFTER
);
check('findOneAndReplace AFTER returns doc', $replaced !== null);
check('findOneAndReplace AFTER: has new field', ($replaced['new'] ?? null) === true);
check('findOneAndReplace AFTER: has version', ($replaced['version'] ?? null) === 2);
check('findOneAndReplace AFTER: old field gone', ! isset($replaced['old']));

// ============================================================
echo "\n=== findOne with projection ===\n";
// ============================================================

$col->deleteMany(['name' => 'Projected']);
$col->insertOne(['name' => 'Projected', 'age' => 25, 'city' => 'Berlin', 'score' => 88]);

$projected = $col->findOne(
    ['name' => 'Projected'],
    ['projection' => ['_id' => 0, 'name' => 1]],
);
check('findOne projection returns doc', $projected !== null);
check('findOne projection: name present', isset($projected['name']));
check('findOne projection: _id excluded', ! isset($projected['_id']));
check('findOne projection: age excluded', ! isset($projected['age']));
check('findOne projection: city excluded', ! isset($projected['city']));

// ============================================================
echo "\n=== find with sort, limit, skip ===\n";
// ============================================================

$col->deleteMany(['group' => 'sorttest']);
$col->insertMany([
    ['group' => 'sorttest', 'name' => 'A', 'age' => 10],
    ['group' => 'sorttest', 'name' => 'B', 'age' => 20],
    ['group' => 'sorttest', 'name' => 'C', 'age' => 30],
    ['group' => 'sorttest', 'name' => 'D', 'age' => 40],
    ['group' => 'sorttest', 'name' => 'E', 'age' => 50],
]);

$cursor = $col->find(
    ['group' => 'sorttest'],
    ['sort' => ['age' => 1], 'limit' => 2, 'skip' => 1],
);

$results = [];
foreach ($cursor as $doc) {
    $results[] = $doc;
}

check('find sort/limit/skip returns 2 docs', count($results) === 2);
if (count($results) === 2) {
    check('find sort/limit/skip: first doc is B (age 20)', ($results[0]['name'] ?? null) === 'B');
    check('find sort/limit/skip: second doc is C (age 30)', ($results[1]['name'] ?? null) === 'C');
} else {
    // Add placeholders so count is consistent
    check('find sort/limit/skip: first doc is B (age 20)', false);
    check('find sort/limit/skip: second doc is C (age 30)', false);
}

// ============================================================
echo "\n=== findOne with sort ===\n";
// ============================================================

$highest = $col->findOne(
    ['group' => 'sorttest'],
    ['sort' => ['age' => -1]],
);
check('findOne with sort returns doc', $highest !== null);
check('findOne sort desc: returns highest age (E, 50)', ($highest['name'] ?? null) === 'E');
check('findOne sort desc: age is 50', ($highest['age'] ?? null) === 50);

// ============================================================
// Cleanup
// ============================================================
$col->drop();

echo "\n========================================\n";
echo "Results: $pass passed, $fail failed\n";
echo "========================================\n";
if (count($errors) > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}

exit($fail > 0 ? 1 : 0);
