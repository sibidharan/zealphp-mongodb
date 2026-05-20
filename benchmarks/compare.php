<?php

/**
 * zealphp-mongodb vs ext-mongodb (C driver) benchmark.
 *
 * Runs identical operations on both drivers and reports median timing.
 * Requires both extensions loaded and mongodb/mongodb composer package.
 *
 * Usage: php benchmarks/compare.php [iterations] [mongodb_uri]
 */

$iterations = (int) ($argv[1] ?? 100);
$uri = $argv[2] ?? 'mongodb://db.selfmade.ninja:27017';
$dbName = 'zealphp_benchmark';
$colName = 'bench_' . getmypid();

require_once __DIR__ . '/../vendor/autoload.php';

// ── Drivers ──────────────────────────────────────────────────────────────────

// zealphp-mongodb (Rust)
$zealClient = new \ZealPHP\MongoDB\Client($uri);
$zealDb = $zealClient->selectDatabase($dbName);
$zealCol = $zealDb->selectCollection($colName);

// ext-mongodb (C)
$cClient = new \MongoDB\Client($uri);
$cDb = $cClient->selectDatabase($dbName);
$cCol = $cDb->selectCollection($colName);

// ── Helpers ──────────────────────────────────────────────────────────────────

function median(array $times): float
{
    sort($times);
    $n = count($times);
    $mid = intdiv($n, 2);
    return $n % 2 === 0
        ? ($times[$mid - 1] + $times[$mid]) / 2
        : $times[$mid];
}

function p95(array $times): float
{
    sort($times);
    $idx = (int) ceil(0.95 * count($times)) - 1;
    return $times[max(0, $idx)];
}

function runBench(string $label, int $iters, callable $zealFn, callable $cFn): array
{
    $zealTimes = [];
    $cTimes = [];

    // Warmup (3 iterations each, discard)
    for ($i = 0; $i < 3; $i++) {
        $zealFn();
        $cFn();
    }

    for ($i = 0; $i < $iters; $i++) {
        $t = hrtime(true);
        $zealFn();
        $zealTimes[] = (hrtime(true) - $t) / 1e6;

        $t = hrtime(true);
        $cFn();
        $cTimes[] = (hrtime(true) - $t) / 1e6;
    }

    $zealMedian = median($zealTimes);
    $cMedian = median($cTimes);
    $gap = $cMedian > 0 ? (($zealMedian - $cMedian) / $cMedian) * 100 : 0;

    return [
        'label' => $label,
        'zeal_median' => $zealMedian,
        'zeal_p95' => p95($zealTimes),
        'c_median' => $cMedian,
        'c_p95' => p95($cTimes),
        'gap_pct' => $gap,
    ];
}

// ── Setup: seed data ─────────────────────────────────────────────────────────

echo "zealphp-mongodb vs ext-mongodb benchmark\n";
echo str_repeat('=', 60) . "\n";
echo "URI:        $uri\n";
echo "Iterations: $iterations per operation\n";
echo "PHP:        " . PHP_VERSION . "\n";
echo "Seeding test data...\n\n";

// Clean up any previous run
try { $zealCol->drop(); } catch (\Throwable $e) {}
try { $cCol->drop(); } catch (\Throwable $e) {}

// Seed 1000 documents via C driver (neutral ground)
$docs = [];
for ($i = 0; $i < 1000; $i++) {
    $docs[] = [
        'index' => $i,
        'name' => "user_$i",
        'email' => "user{$i}@example.com",
        'score' => rand(0, 10000),
        'tags' => ['bench', 'test', "group_" . ($i % 10)],
        'nested' => ['level1' => ['level2' => "value_$i"]],
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
    ];
}
$cCol->insertMany($docs);
echo "Seeded 1,000 documents.\n\n";

// ── Benchmarks ───────────────────────────────────────────────────────────────

$results = [];

// 1. findOne (by indexed field)
$results[] = runBench('findOne', $iterations,
    fn() => $zealCol->findOne(['index' => rand(0, 999)]),
    fn() => $cCol->findOne(['index' => rand(0, 999)])
);

// 2. find + toArray (fetch 50 docs)
$results[] = runBench('find(50)', $iterations,
    fn() => $zealCol->find(['score' => ['$gte' => rand(0, 5000)]], ['limit' => 50])->toArray(),
    fn() => $cCol->find(['score' => ['$gte' => rand(0, 5000)]], ['limit' => 50])->toArray()
);

// 3. find + toArray (fetch all 1000)
$results[] = runBench('find(1000)', $iterations,
    fn() => $zealCol->find([])->toArray(),
    fn() => $cCol->find([])->toArray()
);

// 4. insertOne
$results[] = runBench('insertOne', $iterations,
    fn() => $zealCol->insertOne(['bench' => true, 'ts' => microtime(true), 'driver' => 'zeal']),
    fn() => $cCol->insertOne(['bench' => true, 'ts' => microtime(true), 'driver' => 'c'])
);

// 5. updateOne
$results[] = runBench('updateOne', $iterations,
    fn() => $zealCol->updateOne(['index' => rand(0, 999)], ['$set' => ['updated' => true]]),
    fn() => $cCol->updateOne(['index' => rand(0, 999)], ['$set' => ['updated' => true]])
);

// 6. deleteOne (insert then delete to keep data stable)
$results[] = runBench('deleteOne', $iterations,
    function () use ($zealCol) {
        $id = $zealCol->insertOne(['tmp' => true])->getInsertedId();
        $zealCol->deleteOne(['_id' => $id]);
    },
    function () use ($cCol) {
        $id = $cCol->insertOne(['tmp' => true])->getInsertedId();
        $cCol->deleteOne(['_id' => $id]);
    }
);

// 7. countDocuments
$results[] = runBench('countDocuments', $iterations,
    fn() => $zealCol->countDocuments(['score' => ['$gte' => 5000]]),
    fn() => $cCol->countDocuments(['score' => ['$gte' => 5000]])
);

// 8. aggregate (group by tag, sum scores)
$pipeline = [
    ['$unwind' => '$tags'],
    ['$group' => ['_id' => '$tags', 'total' => ['$sum' => '$score']]],
    ['$sort' => ['total' => -1]],
    ['$limit' => 5],
];
$results[] = runBench('aggregate', $iterations,
    fn() => $zealCol->aggregate($pipeline)->toArray(),
    fn() => $cCol->aggregate($pipeline)->toArray()
);

// 9. distinct
$results[] = runBench('distinct', $iterations,
    fn() => $zealCol->distinct('tags', ['score' => ['$gte' => 5000]]),
    fn() => $cCol->distinct('tags', ['score' => ['$gte' => 5000]])
);

// 10. findOneAndUpdate
$results[] = runBench('findOneAndUpdate', $iterations,
    fn() => $zealCol->findOneAndUpdate(
        ['index' => rand(0, 999)],
        ['$inc' => ['score' => 1]],
        ['returnDocument' => 'after']
    ),
    fn() => $cCol->findOneAndUpdate(
        ['index' => rand(0, 999)],
        ['$inc' => ['score' => 1]],
        ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    )
);

// ── Results ──────────────────────────────────────────────────────────────────

echo str_repeat('─', 85) . "\n";
printf("%-20s %12s %12s %12s %12s %10s\n",
    'Operation', 'Zeal med', 'Zeal p95', 'C med', 'C p95', 'Gap');
echo str_repeat('─', 85) . "\n";

foreach ($results as $r) {
    $gapStr = sprintf('%+.1f%%', $r['gap_pct']);
    if ($r['gap_pct'] <= 0) {
        $gapStr = $gapStr; // faster or equal
    }
    printf("%-20s %10.3fms %10.3fms %10.3fms %10.3fms %10s\n",
        $r['label'],
        $r['zeal_median'], $r['zeal_p95'],
        $r['c_median'], $r['c_p95'],
        $gapStr
    );
}

echo str_repeat('─', 85) . "\n";

// Summary
$totalZeal = array_sum(array_column($results, 'zeal_median'));
$totalC = array_sum(array_column($results, 'c_median'));
$totalGap = $totalC > 0 ? (($totalZeal - $totalC) / $totalC) * 100 : 0;
printf("%-20s %10.3fms %12s %10.3fms %12s %10s\n",
    'TOTAL (medians)', $totalZeal, '', $totalC, '', sprintf('%+.1f%%', $totalGap));
echo str_repeat('─', 85) . "\n";

// JSON output for CI/tracking
$jsonPath = __DIR__ . '/results.json';
file_put_contents($jsonPath, json_encode([
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'iterations' => $iterations,
    'uri' => $uri,
    'results' => $results,
], JSON_PRETTY_PRINT) . "\n");
echo "\nJSON results saved to $jsonPath\n";

// Cleanup
try { $zealCol->drop(); } catch (\Throwable $e) {}

echo "Done.\n";
