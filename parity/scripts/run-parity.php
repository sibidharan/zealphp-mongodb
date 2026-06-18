<?php

declare(strict_types=1);

/**
 * Side-by-side HTTP parity runner: fires every op at the Apache(C) and
 * ZealPHP(Rust) endpoints and deep-diffs the normalized results.
 *
 *   php run-parity.php http://127.0.0.1:8089/api.php http://127.0.0.1:8090/api
 */
[$_, $cBase, $zBase] = $argv + [null, 'http://127.0.0.1:8089/api.php', 'http://127.0.0.1:8090/api'];

$ops = ['reset', 'crud', 'query', 'aggregate', 'types', 'indexes', 'bulk', 'txn_commit', 'txn_abort', 'change_stream', 'gridfs', 'errors', 'options', 'int_types', 'write_results', 'insert_ids', 'bulk_ids', 'find_opts'];

function fetch(string $base, string $op): array
{
    $ch = curl_init("$base?op=$op");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode((string) $body, true);

    return ['code' => $code, 'json' => $j, 'raw' => $body];
}

/** Recursive diff returning the paths that differ. */
function diff(mixed $a, mixed $b, string $path = ''): array
{
    if (is_array($a) && is_array($b)) {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (! array_key_exists($k, $a)) {
                $out[] = "$path.$k only-in-RUST: " . json_encode($b[$k]);
            } elseif (! array_key_exists($k, $b)) {
                $out[] = "$path.$k only-in-C: " . json_encode($a[$k]);
            } else {
                $out = array_merge($out, diff($a[$k], $b[$k], "$path.$k"));
            }
        }

        return $out;
    }

    return $a === $b ? [] : ["$path C=" . json_encode($a) . ' RUST=' . json_encode($b)];
}

$pass = $fail = 0;
foreach ($ops as $op) {
    $c = fetch($cBase, $op);
    $z = fetch($zBase, $op);

    if ($c['code'] !== 200 || isset($c['json']['error'])) {
        printf("✗ %-14s C-path error: %s\n", $op, $c['json']['error'] ?? "HTTP {$c['code']}: {$c['raw']}");
        $fail++;
        continue;
    }

    if ($z['code'] !== 200 || isset($z['json']['error'])) {
        printf("✗ %-14s RUST-path error: %s\n", $op, $z['json']['error'] ?? "HTTP {$z['code']}: {$z['raw']}");
        $fail++;
        continue;
    }

    $d = diff($c['json']['result'], $z['json']['result']);
    if ($d === []) {
        printf("✓ %-14s identical\n", $op);
        $pass++;
    } else {
        printf("✗ %-14s %d differences:\n", $op, count($d));
        foreach (array_slice($d, 0, 6) as $line) {
            echo "    $line\n";
        }

        $fail++;
    }
}

printf("\n%d/%d ops identical across C and Rust drivers\n", $pass, $pass + $fail);
exit($fail === 0 ? 0 : 1);
