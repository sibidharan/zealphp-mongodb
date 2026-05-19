<?php

declare(strict_types=1);

/**
 * Concerns Tests
 *
 * Tests ReadConcern, WriteConcern, ReadPreference construction and methods,
 * plus Client/Database/Collection concern accessors.
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
use ZealPHP\MongoDB\ReadConcern;
use ZealPHP\MongoDB\ReadPreference;
use ZealPHP\MongoDB\WriteConcern;

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

// ============================================================
echo "=== ReadConcern ===\n";
// ============================================================

// Default (no level)
$rc = new ReadConcern();
check('ReadConcern default getLevel is null', $rc->getLevel() === null);
check('ReadConcern default isDefault is true', $rc->isDefault() === true);

// With level
$rcLocal = new ReadConcern('local');
check('ReadConcern local getLevel', $rcLocal->getLevel() === 'local');
check('ReadConcern local isDefault is false', $rcLocal->isDefault() === false);

$rcMaj = new ReadConcern(ReadConcern::MAJORITY);
check('ReadConcern majority getLevel', $rcMaj->getLevel() === 'majority');

// Constants
check('ReadConcern::LINEARIZABLE', ReadConcern::LINEARIZABLE === 'linearizable');
check('ReadConcern::LOCAL', ReadConcern::LOCAL === 'local');
check('ReadConcern::MAJORITY', ReadConcern::MAJORITY === 'majority');
check('ReadConcern::AVAILABLE', ReadConcern::AVAILABLE === 'available');
check('ReadConcern::SNAPSHOT', ReadConcern::SNAPSHOT === 'snapshot');

// JsonSerializable
check('ReadConcern implements JsonSerializable', $rc instanceof JsonSerializable);
$defaultJson = $rc->jsonSerialize();
check('ReadConcern default json is empty stdClass', $defaultJson instanceof stdClass);

$localJson = $rcLocal->jsonSerialize();
check('ReadConcern local json has level', is_array($localJson) && ($localJson['level'] ?? null) === 'local');

// ============================================================
echo "\n=== WriteConcern ===\n";
// ============================================================

// Basic w=1
$wc = new WriteConcern(1);
check('WriteConcern w=1 getW', $wc->getW() === 1);
check('WriteConcern w=1 getJournal is null', $wc->getJournal() === null);
check('WriteConcern w=1 getWtimeout is 0', $wc->getWtimeout() === 0);

// With journal and wtimeout
$wc2 = new WriteConcern(1, 5000, true);
check('WriteConcern getW', $wc2->getW() === 1);
check('WriteConcern getWtimeout', $wc2->getWtimeout() === 5000);
check('WriteConcern getJournal', $wc2->getJournal() === true);

// String w (majority)
$wcMaj = new WriteConcern(WriteConcern::MAJORITY);
check('WriteConcern majority getW', $wcMaj->getW() === 'majority');

// Constant
check('WriteConcern::MAJORITY', WriteConcern::MAJORITY === 'majority');

// JsonSerializable
check('WriteConcern implements JsonSerializable', $wc instanceof JsonSerializable);
$wcJson = $wc2->jsonSerialize();
check('WriteConcern json has w', ($wcJson['w'] ?? null) === 1);
check('WriteConcern json has j', ($wcJson['j'] ?? null) === true);
check('WriteConcern json has wtimeout', ($wcJson['wtimeout'] ?? null) === 5000);

// isDefault
$wcDefault = new WriteConcern(1);
check('WriteConcern isDefault behavior', is_bool($wcDefault->isDefault()));

// ============================================================
echo "\n=== ReadPreference ===\n";
// ============================================================

// Primary
$rp = new ReadPreference(ReadPreference::PRIMARY);
check('ReadPreference primary getModeString', $rp->getModeString() === 'primary');
check('ReadPreference primary getTagSets empty', $rp->getTagSets() === []);

// Secondary with tags
$tags = [['dc' => 'east']];
$rpSec = new ReadPreference(ReadPreference::SECONDARY, $tags);
check('ReadPreference secondary getModeString', $rpSec->getModeString() === 'secondary');
check('ReadPreference secondary getTagSets', $rpSec->getTagSets() === $tags);

// With maxStalenessSeconds
$rpNearest = new ReadPreference(ReadPreference::NEAREST, null, ['maxStalenessSeconds' => 120]);
check('ReadPreference nearest getModeString', $rpNearest->getModeString() === 'nearest');
check('ReadPreference getMaxStalenessSeconds', $rpNearest->getMaxStalenessSeconds() === 120);

// Default maxStalenessSeconds
$rpDefault = new ReadPreference(ReadPreference::PRIMARY);
check('ReadPreference default maxStaleness is NO_MAX_STALENESS', $rpDefault->getMaxStalenessSeconds() === ReadPreference::NO_MAX_STALENESS);

// Constants
check('ReadPreference::PRIMARY', ReadPreference::PRIMARY === 'primary');
check('ReadPreference::PRIMARY_PREFERRED', ReadPreference::PRIMARY_PREFERRED === 'primaryPreferred');
check('ReadPreference::SECONDARY', ReadPreference::SECONDARY === 'secondary');
check('ReadPreference::SECONDARY_PREFERRED', ReadPreference::SECONDARY_PREFERRED === 'secondaryPreferred');
check('ReadPreference::NEAREST', ReadPreference::NEAREST === 'nearest');
check('ReadPreference::NO_MAX_STALENESS', ReadPreference::NO_MAX_STALENESS === -1);
check('ReadPreference::SMALLEST_MAX_STALENESS_SECONDS', ReadPreference::SMALLEST_MAX_STALENESS_SECONDS === 90);

// JsonSerializable
check('ReadPreference implements JsonSerializable', $rp instanceof JsonSerializable);
$rpJson = $rp->jsonSerialize();
check('ReadPreference json has mode', ($rpJson['mode'] ?? null) === 'primary');

// ============================================================
echo "\n=== Client/Database/Collection concern accessors ===\n";
// ============================================================

$client = new Client('mongodb://db.selfmade.ninja:27017');
$db = $client->selectDatabase('zealphp_test');
$col = $db->selectCollection('test_concerns_check');

// Client concerns
$clientRC = $client->getReadConcern();
check('Client getReadConcern returns ReadConcern', $clientRC instanceof ReadConcern);
check('Client getReadConcern isDefault', $clientRC->isDefault());

$clientWC = $client->getWriteConcern();
check('Client getWriteConcern returns WriteConcern', $clientWC instanceof WriteConcern);
check('Client getWriteConcern getW is 1', $clientWC->getW() === 1);

$clientRP = $client->getReadPreference();
check('Client getReadPreference returns ReadPreference', $clientRP instanceof ReadPreference);
check('Client getReadPreference mode is primary', $clientRP->getModeString() === 'primary');

$clientTM = $client->getTypeMap();
check('Client getTypeMap returns array', is_array($clientTM));
check('Client getTypeMap has root', array_key_exists('root', $clientTM));

// Database concerns
$dbRC = $db->getReadConcern();
check('Database getReadConcern returns ReadConcern', $dbRC instanceof ReadConcern);

$dbWC = $db->getWriteConcern();
check('Database getWriteConcern returns WriteConcern', $dbWC instanceof WriteConcern);

$dbRP = $db->getReadPreference();
check('Database getReadPreference returns ReadPreference', $dbRP instanceof ReadPreference);

$dbTM = $db->getTypeMap();
check('Database getTypeMap returns array', is_array($dbTM));

// Collection concerns
$colRC = $col->getReadConcern();
check('Collection getReadConcern returns ReadConcern', $colRC instanceof ReadConcern);

$colWC = $col->getWriteConcern();
check('Collection getWriteConcern returns WriteConcern', $colWC instanceof WriteConcern);

$colRP = $col->getReadPreference();
check('Collection getReadPreference returns ReadPreference', $colRP instanceof ReadPreference);

$colTM = $col->getTypeMap();
check('Collection getTypeMap returns array', is_array($colTM));
check('Collection getTypeMap root is array', ($colTM['root'] ?? null) === 'array');
check('Collection getTypeMap document is array', ($colTM['document'] ?? null) === 'array');
check('Collection getTypeMap array is array', ($colTM['array'] ?? null) === 'array');

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
