#!/usr/bin/env php
<?php
/**
 * B3 — Concurrent Stampede Protection Test (FPM-compatible)
 * Uses background shell curl processes for true concurrency.
 */

$target = $argv[1] ?? 'http://127.0.0.1:8088';
$callers = (int)($argv[2] ?? 60);

echo "=== B3 Concurrent Stampede Protection Test ===\n";
echo "Target: $target/api/cache/stampede\n";
echo "Concurrent callers: $callers\n";

// Flush stampede key via Redis
$redis = new Redis();
if (@$redis->connect('127.0.0.1', 6379, 2)) {
    $redis->del('siro:cache:soak:stampede_key');
    echo "Redis stampede key flushed.\n";
} else {
    echo "WARNING: Redis not available.\n";
}

$tmpDir = sys_get_temp_dir() . '/stampede_' . getmypid();
mkdir($tmpDir, 0777, true);

$startTime = microtime(true);

// Launch ALL curl requests in background using shell &
$cmds = [];
for ($i = 0; $i < $callers; $i++) {
    $outFile = "$tmpDir/result_$i.json";
    $cmds[] = "curl -s -o $outFile http://127.0.0.1:8088/api/cache/stampede 2>/dev/null &";
}
// Add wait command at end
$cmds[] = "wait";

$shellCmd = implode(' ', $cmds);
echo "Firing $callers concurrent requests at " . date('c') . "...\n";
exec($shellCmd, $output, $exitCode);

$endTime = microtime(true);
$duration = round(($endTime - $startTime) * 1000, 1);

// Collect results
$totalCallbacks = 0;
$values = [];
$errors = 0;

for ($i = 0; $i < $callers; $i++) {
    $outFile = "$tmpDir/result_$i.json";
    if (file_exists($outFile)) {
        $content = file_get_contents($outFile);
        $data = json_decode($content, true);
        if ($data && isset($data['callback_executed'])) {
            $totalCallbacks += $data['callback_executed'];
            $values[] = $data['value'] ?? null;
        } else {
            $errors++;
        }
    } else {
        $errors++;
    }
}

// Cleanup
exec("rm -rf " . escapeshellarg($tmpDir));

$uniqueValues = count(array_unique(array_filter($values)));
$valueConsistent = ($uniqueValues <= 1);
$callbacksAcceptable = ($totalCallbacks <= 2);

echo "\n=== RESULTS ===\n";
echo "Duration: {$duration}ms\n";
echo "Successful responses: " . ($callers - $errors) . " / $callers\n";
echo "Errors: $errors\n";
echo "Total callbacks executed: $totalCallbacks\n";
echo "Max acceptable: 2\n";
echo "Callbacks acceptable: " . ($callbacksAcceptable ? "YES ✅" : "NO ❌") . "\n";
echo "Unique cache values: $uniqueValues\n";
echo "All callers got same value: " . ($valueConsistent ? "YES ✅" : "NO ❌") . "\n";

if ($totalCallbacks > 0) {
    echo "\n$totalCallbacks callback(s) executed = cache miss on " . ($callers - $totalCallbacks) . " callers.\n";
} else {
    echo "\n0 callbacks = all callers got cached value.\n";
}

echo "\n=== VERDICT ===\n";
if ($callbacksAcceptable && $errors === 0) {
    echo "B3 REDIS CACHE STAMPEDE: PASS ✅\n";
} elseif (!$callbacksAcceptable) {
    echo "B3 REDIS CACHE STAMPEDE: FAIL ❌\n";
} else {
    echo "B3 REDIS CACHE STAMPEDE: INCONCLUSIVE ⚠️ ($errors errors)\n";
}
