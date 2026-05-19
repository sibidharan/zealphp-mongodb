<?php

declare(strict_types=1);

// Load zealphp/mongodb

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use ZealPHP\MongoDB\Client;

$base = __DIR__ . '/../php/src';
require_once "$base/Client.php";
require_once "$base/Database.php";
require_once "$base/Collection.php";
require_once "$base/Cursor.php";
require_once "$base/InsertOneResult.php";
require_once "$base/UpdateResult.php";
require_once "$base/DeleteResult.php";

// Load official mongodb/mongodb
require_once '/var/www/labs-dashboard-web/htdocs/vendor/autoload.php';

$uri = 'mongodb://db.selfmade.ninja:27017';
$dbName = 'selfmadelabs';

echo "=== Parity Test: zealphp/mongodb vs mongodb/mongodb ===\n\n";

$zClient = new Client($uri);
$mClient = new MongoDB\Client($uri);

$zDb = $zClient->selectDatabase($dbName);
$mDb = $mClient->selectDatabase($dbName);

$passed = 0;
$failed = 0;

function compare($label, $z, $m)
{
    global $passed, $failed;
    // Normalize mongodb/mongodb BSONDocument to array
    if ($m instanceof BSONDocument || $m instanceof BSONArray) {
        $m = json_decode(json_encode($m), true);
    }

    if (is_array($m)) {
        array_walk_recursive($m, static function (&$v) {
            if ($v instanceof MongoDB\Model\BSONDocument) {
                $v = json_decode(json_encode($v), true);
            }

            if ($v instanceof MongoDB\Model\BSONArray) {
                $v = json_decode(json_encode($v), true);
            }

            if ($v instanceof ObjectId) {
                $v = (string) $v;
            }

            if (! ($v instanceof UTCDateTime)) {
                return;
            }

            $v = $v->toDateTime()->getTimestamp();
        });
    }

    if ($z === $m) {
        echo "  ✓ $label\n";
        $passed++;
    } elseif (is_numeric($z) && is_numeric($m) && $z === $m) {
        echo "  ✓ $label (numeric match)\n";
        $passed++;
    } else {
        echo "  ✗ $label\n";
        $failed++;
        echo '    zealphp: ' . substr(var_export($z, true), 0, 100) . "\n";
        echo '    mongodb: ' . substr(var_export($m, true), 0, 100) . "\n";
    }
}

// countDocuments
echo "countDocuments:\n";
$zCount = $zDb->selectCollection('users')->countDocuments([]);
$mCount = $mDb->selectCollection('users')->countDocuments([]);
compare('users count', $zCount, $mCount);

// findOne
echo "\nfindOne:\n";
$zUser = $zDb->selectCollection('users')->findOne(['id' => 1]);
$mUser = $mDb->selectCollection('users')->findOne(['id' => 1]);
compare('user id=1 username', $zUser['username'] ?? null, $mUser['username'] ?? null);
compare('user id=1 email', $zUser['email'] ?? null, $mUser['email'] ?? null);

// find + count results
echo "\nfind:\n";
$zResults = $zDb->selectCollection('users')->find(['id' => ['$lte' => 5]])->toArray();
$mResults = $mDb->selectCollection('users')->find(['id' => ['$lte' => 5]])->toArray();
compare('find count', count($zResults), count($mResults));

// aggregate
echo "\naggregate:\n";
$pipeline = [['$group' => ['_id' => null, 'total' => ['$sum' => 1]]]];
$zAgg = $zDb->selectCollection('users')->aggregate($pipeline)->toArray();
$mAgg = $mDb->selectCollection('users')->aggregate($pipeline)->toArray();
compare('aggregate total', $zAgg[0]['total'] ?? -1, $mAgg[0]['total'] ?? -2);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
