<?php
declare(strict_types=1);

/**
 * Cache Stampede Baseline Test
 *
 * Spawns N concurrent PHP processes, each calling Cache::remember() on the
 * same missing key. Measures how many times the callback executes.
 *
 * Usage: php cache-stampede-test.php [workers=20]
 *
 * BEFORE FIX: expect callback_executed ≈ workers (stampede)
 * AFTER FIX:  expect callback_executed = 1 (protected)
 */

$workers = (int) ($argv[1] ?? 20);
$testId = 'stampede_' . uniqid();
$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

echo "=== Cache Stampede Test ===\n";
echo "Workers:   {$workers}\n";
echo "Test ID:   {$testId}\n";

// Setup isolated cache dir
$cacheDir = sys_get_temp_dir() . '/siro_stampede_' . $testId;
if (is_dir($cacheDir)) {
    array_map('unlink', glob($cacheDir . '/*') ?: []);
    rmdir($cacheDir);
}
mkdir($cacheDir, 0777, true);

// Counter files
$counterFile = $cacheDir . '/counter';
$counterPath = $counterFile . '_computation_count';
$logFile = $counterPath . '_log';
@unlink($counterPath);
@unlink($logFile);

// Clean any existing cache for this key
$key = 'expensive_result_' . $testId;
$safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
$safeKey = substr($safeKey, 0, 200) . '_' . sha1('siro:' . $key);
@unlink($cacheDir . '/' . $safeKey . '.cache');

echo "Cache dir: {$cacheDir}\n";
echo "Key:       {$key}\n\n";

// Launch all workers simultaneously using proc_open
$processes = [];
$startTime = microtime(true);

for ($i = 0; $i < $workers; $i++) {
    $cmd = sprintf(
        'php "%s" "%s" "%s" "%s" "%s" 2>&1',
        escapeshellarg(__DIR__ . '/cache-stampede-worker.php'),
        escapeshellarg($counterFile),
        escapeshellarg($cacheDir),
        escapeshellarg($key),
        escapeshellarg("worker_{$i}")
    );

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = proc_open($cmd, $desc, $pipes);

    if (!is_resource($proc)) {
        echo "ERROR: Failed to launch worker {$i}\n";
        continue;
    }

    $processes[$i] = [
        'proc' => $proc,
        'pipes' => $pipes,
    ];
}

// Collect results
$results = [];
foreach ($processes as $i => $p) {
    $output = stream_get_contents($p['pipes'][1]);
    fclose($p['pipes'][0]);
    fclose($p['pipes'][1]);
    fclose($p['pipes'][2]);
    $exitCode = proc_close($p['proc']);

    $line = trim($output);
    if ($line !== '') {
        $decoded = json_decode($line, true);
        if ($decoded) {
            $results[] = $decoded;
        }
    }
}

$elapsed = round(microtime(true) - $startTime, 3);

// Read computation count
$callbackExecuted = 0;
if (file_exists($counterPath)) {
    $callbackExecuted = (int) file_get_contents($counterPath);
}

// Read computation log
$callbackLog = [];
if (file_exists($logFile)) {
    $callbackLog = array_filter(explode("\n", file_get_contents($logFile)));
}

// Read cached value
$cachedValue = \Siro\Core\Cache::get($key);

// Summary
echo "=== Results ===\n";
echo "Elapsed:              {$elapsed}s\n";
echo "Workers completed:    " . count($results) . "/{$workers}\n";
echo "Callback executed:    {$callbackExecuted} time(s)\n";
echo "Cached value:         " . var_export($cachedValue, true) . "\n";
echo "All same value:       " . (count(array_unique(array_column($results, 'result'))) <= 1 ? 'YES' : 'NO') . "\n";
echo "\n";

// Verdict
if ($callbackExecuted <= 1) {
    echo "STATUS: PROTECTED — callback executed {$callbackExecuted} time(s)\n";
} else {
    echo "STATUS: STAMPEDE — callback executed {$callbackExecuted} time(s)\n";
}

echo "\nCallback log (workers that executed the callback):\n";
foreach (array_values($callbackLog) as $w) {
    echo "  - {$w}\n";
}

echo "\nPer-worker results:\n";
foreach ($results as $r) {
    echo "  {$r['worker_id']}: {$r['result']}\n";
}

// Cleanup
array_map('unlink', glob($cacheDir . '/*') ?: []);
rmdir($cacheDir);

// Exit code: 0 = protected, 1 = stampede
exit($callbackExecuted <= 1 ? 0 : 1);
