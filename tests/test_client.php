<?php
/**
 * Client API Tests
 *
 * Tests listDatabases, listDatabaseNames, dropDatabase, selectDatabase,
 * selectCollection, startSession, watch, __toString, __debugInfo
 * against live MongoDB.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require_once $file;
});

use ZealPHP\MongoDB\Client;
use ZealPHP\MongoDB\Database;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Session;
use ZealPHP\MongoDB\ChangeStream;

$pass = 0; $fail = 0; $errors = [];
function check($label, $cond) {
    global $pass, $fail, $errors;
    if ($cond) { $pass++; }
    else { $fail++; $errors[] = $label; echo "FAIL $label\n"; }
}

$client = new Client('mongodb://db.selfmade.ninja:27017');

// ============================================================
echo "=== listDatabases / listDatabaseNames ===\n";
// ============================================================

// Ensure at least one database exists by creating a temp collection
$tmpDb = $client->selectDatabase('zealphp_test');
$tmpCol = $tmpDb->selectCollection('test_client_marker');
$tmpCol->insertOne(['marker' => true]);

$dbNames = $client->listDatabaseNames();
check('listDatabaseNames returns array', is_array($dbNames));
check('listDatabaseNames has at least 1 entry', count($dbNames) >= 1);
check('listDatabaseNames contains zealphp_test', in_array('zealphp_test', $dbNames));

$dbList = $client->listDatabases();
check('listDatabases returns array', is_array($dbList));
check('listDatabases has at least 1 entry', count($dbList) >= 1);
// Each entry should have a 'name' key
$firstEntry = $dbList[0] ?? null;
check('listDatabases entries have name key', $firstEntry !== null && isset($firstEntry['name']));

// Check zealphp_test is in the full list
$found = false;
foreach ($dbList as $dbInfo) {
    if (($dbInfo['name'] ?? '') === 'zealphp_test') {
        $found = true;
        break;
    }
}
check('listDatabases includes zealphp_test', $found);

$tmpCol->drop();

// ============================================================
echo "\n=== dropDatabase ===\n";
// ============================================================

$dropDbName = 'zealphp_test_client_drop_' . time();
$dropDb = $client->selectDatabase($dropDbName);
$dropDb->createCollection('tmp');

// Verify it exists
$namesBeforeDrop = $client->listDatabaseNames();
check('temp db exists before dropDatabase', in_array($dropDbName, $namesBeforeDrop));

$result = $client->dropDatabase($dropDbName);
check('dropDatabase returns ok', ($result['ok'] ?? 0) == 1);

$namesAfterDrop = $client->listDatabaseNames();
check('temp db gone after dropDatabase', !in_array($dropDbName, $namesAfterDrop));

// ============================================================
echo "\n=== selectDatabase ===\n";
// ============================================================

$db = $client->selectDatabase('zealphp_test');
check('selectDatabase returns Database', $db instanceof Database);
check('selectDatabase has correct name', $db->getDatabaseName() === 'zealphp_test');

// Test __get magic method
$dbMagic = $client->zealphp_test;
check('__get returns Database', $dbMagic instanceof Database);
check('__get has correct name', $dbMagic->getDatabaseName() === 'zealphp_test');

// ============================================================
echo "\n=== selectCollection ===\n";
// ============================================================

$col = $client->selectCollection('zealphp_test', 'some_collection');
check('selectCollection returns Collection', $col instanceof Collection);
check('selectCollection has correct collection name', $col->getCollectionName() === 'some_collection');
check('selectCollection has correct database name', $col->getDatabaseName() === 'zealphp_test');
check('selectCollection getNamespace', $col->getNamespace() === 'zealphp_test.some_collection');

// ============================================================
echo "\n=== startSession ===\n";
// ============================================================

$session = $client->startSession();
check('startSession returns Session', $session instanceof Session);
check('session transaction state is none', $session->getTransactionState() === Session::TRANSACTION_NONE);
check('session isInTransaction is false', $session->isInTransaction() === false);

// Test session transaction lifecycle
$session->startTransaction();
check('after startTransaction, isInTransaction is true', $session->isInTransaction());
check('transaction state is in_progress', $session->getTransactionState() === Session::TRANSACTION_IN_PROGRESS);

$session->commitTransaction();
check('after commit, isInTransaction is false', $session->isInTransaction() === false);
check('transaction state is committed', $session->getTransactionState() === Session::TRANSACTION_COMMITTED);

// Another session with abort
$session2 = $client->startSession();
$session2->startTransaction();
$session2->abortTransaction();
check('after abort, transaction state is aborted', $session2->getTransactionState() === Session::TRANSACTION_ABORTED);

// Session logical ID
$logicalId = $session->getLogicalSessionId();
check('getLogicalSessionId returns object', is_object($logicalId));
check('logical session id has id field', isset($logicalId->id));

// endSession
$session->endSession();
check('after endSession, state is none', $session->getTransactionState() === Session::TRANSACTION_NONE);

// ============================================================
echo "\n=== watch ===\n";
// ============================================================

$changeStream = $client->watch();
check('watch returns ChangeStream', $changeStream instanceof ChangeStream);
check('watch implements Iterator', $changeStream instanceof \Iterator);
check('changeStream valid() is false', $changeStream->valid() === false);
check('changeStream current() is null', $changeStream->current() === null);
check('changeStream getResumeToken() is null', $changeStream->getResumeToken() === null);

// ============================================================
echo "\n=== __toString ===\n";
// ============================================================

$str = (string)$client;
check('__toString returns string', is_string($str));
check('__toString contains mongodb://', str_contains($str, 'mongodb://'));

// ============================================================
echo "\n=== __debugInfo ===\n";
// ============================================================

$debug = $client->__debugInfo();
check('__debugInfo returns array', is_array($debug));
check('__debugInfo has poolId', array_key_exists('poolId', $debug));

echo "\n========================================\n";
echo "Results: $pass passed, $fail failed\n";
echo "========================================\n";
if (count($errors) > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) echo "  - $e\n";
}
exit($fail > 0 ? 1 : 0);
