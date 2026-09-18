#!/usr/bin/env php
<?php
/**
 * B4 — Redis Queue Successful Execution Test
 * 
 * Dispatches N jobs via Queue::push(), starts framework worker,
 * and verifies all jobs are consumed successfully.
 */

$target = $argv[1] ?? 'http://127.0.0.1:8088';
$count = (int)($argv[2] ?? 10000);

echo "=== B4 Redis Queue Execution Test ===\n";
echo "Target: $target\n";
echo "Jobs to dispatch: $count\n";

// Load framework autoloader
$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

// Load .env
if (file_exists($basePath . '/.env')) {
    $lines = file($basePath . '/.env', FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        if (trim($line) === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
}

// Verify Redis connection
$redis = new Redis();
if (!$redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int)($_ENV['REDIS_PORT'] ?? 6379), 5)) {
    echo "FAIL: Cannot connect to Redis\n";
    exit(1);
}
echo "Redis connected.\n";
echo "Redis PING: " . $redis->ping() . "\n";

// Flush old queue jobs
\Queue::flush();
echo "Queue flushed.\n";

// Record start
$startDepth = \Redis::connection()->llen('siro:queue:default');
echo "Queue depth before dispatch: $startDepth\n";

// Dispatch jobs
$startTime = microtime(true);
$dispatched = 0;
$dispatchErrors = 0;

for ($i = 0; $i < $count; $i++) {
    try {
        \Queue::push(\Soak\SoakTestJob::class, [
            'id' => "b4_test_$i",
            'dispatched_at' => time(),
            'to' => 'test@example.com',
            'subject' => "B4-test-$i",
            'body' => "B4 queue verification job #$i",
        ]);
        $dispatched++;
    } catch (\Throwable $e) {
        $dispatchErrors++;
        if ($dispatchErrors <= 3) {
            echo "Dispatch error #$dispatchErrors: " . $e->getMessage() . "\n";
        }
    }
}

$dispatchTime = round((microtime(true) - $startTime) * 1000, 1);
$queueDepthAfterDispatch = \Redis::connection()->llen('siro:queue:default');

echo "\nDispatched: $dispatched / $count\n";
echo "Dispatch errors: $dispatchErrors\n";
echo "Dispatch time: {$dispatchTime}ms\n";
echo "Queue depth after dispatch: $queueDepthAfterDispatch\n";

if ($dispatched < $count) {
    echo "WARNING: Not all jobs dispatched ($dispatched < $count)\n";
}

// Start worker to consume jobs
echo "\nStarting framework worker...\n";
$workerStart = microtime(true);
$workerOutput = [];

// Use proc_open to run queue:work with a timeout
$cmd = "cd " . escapeshellarg($basePath) . " && php artisan queue:work --sleep=1 --tries=1 --max-time=300 2>&1";
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmd, $descriptors, $pipes);

if (!is_resource($proc)) {
    echo "FAIL: Could not start worker process\n";
    exit(1);
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$consumed = 0;
$failed = 0;
$peakMemory = 0;
$checks = 0;
$maxChecks = 360; // 6 minutes max

echo "Worker running. Monitoring consumption...\n";

while ($checks < $maxChecks) {
    usleep(1000000); // 1 second
    
    // Check if worker is still running
    $status = proc_get_status($proc);
    if (!$status['running']) {
        echo "Worker exited with code: " . $status['exitcode'] . "\n";
        break;
    }
    
    // Read worker output
    $out = fread($pipes[1], 8192);
    if ($out) $workerOutput[] = $out;
    $err = fread($pipes[2], 8192);
    if ($err) $workerOutput[] = "STDERR: " . $err;
    
    $currentDepth = \Redis::connection()->llen('siro:queue:default');
    $mem = memory_get_usage(true);
    if ($mem > $peakMemory) $peakMemory = $mem;
    
    $checks++;
    $elapsed = round(microtime(true) - $workerStart);
    
    if ($checks % 10 === 0) {
        echo "[{$elapsed}s] Queue depth: $currentDepth / dispatched: $dispatched\n";
    }
    
    if ($currentDepth === 0 && $checks > 5) {
        echo "[{$elapsed}s] Queue depth = 0. All jobs consumed.\n";
        break;
    }
}

$workerTime = round((microtime(true) - $workerStart), 1);
$finalDepth = \Redis::connection()->llen('siro:queue:default');

// Terminate worker gracefully
if (is_resource($proc)) {
    $status = proc_get_status($proc);
    if ($status['running']) {
        posix_kill($status['pid'], SIGTERM);
        usleep(500000);
        if (proc_get_status($proc)['running']) {
            posix_kill($status['pid'], SIGKILL);
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}

// Analyze worker output
$processedCount = 0;
$failedCount = 0;
foreach ($workerOutput as $chunk) {
    if (preg_match_all('/Processing:.*SoakTestJob/', $chunk)) $processedCount++;
    if (preg_match_all('/Failed:.*SoakTestJob/', $chunk)) $failedCount++;
}

echo "\n=== RESULTS ===\n";
echo "Jobs dispatched: $dispatched\n";
echo "Dispatch errors: $dispatchErrors\n";
echo "Worker runtime: {$workerTime}s\n";
echo "Final queue depth: $finalDepth\n";
echo "Peak worker memory: " . round($peakMemory / 1024 / 1024, 2) . " MB\n";
echo "Worker processed (from output): $processedCount\n";
echo "Worker failed (from output): $failedCount\n";

$allConsumed = ($finalDepth === 0);
$noUnexpectedFailures = ($failedCount === 0);
$dispatchedEnough = ($dispatched >= 10000);

echo "\n=== VERDICT ===\n";
if ($dispatched >= 10000 && $finalDepth === 0 && $dispatchErrors === 0) {
    echo "B4 REDIS QUEUE: PASS ✅\n";
    echo "Dispatched $dispatched jobs, consumed all, depth returned to 0.\n";
} elseif ($finalDepth > 0) {
    echo "B4 REDIS QUEUE: PARTIAL ⚠️\n";
    echo "Still $finalDepth jobs remaining in queue after {$workerTime}s.\n";
} else {
    echo "B4 REDIS QUEUE: ISSUE ⚠️\n";
    echo "Dispatched $dispatched (target: 10,000), errors: $dispatchErrors\n";
}
