<?php
declare(strict_types=1);

/**
 * SiroPHP 48h Soak — Redis Queue Worker v3
 *
 * Uses brPop (same as framework) + proper error logging
 * Lightweight — no framework DB dependency for normal operation
 */

$basePath = dirname(__DIR__, 2);

// Direct Redis connection
$redis = new Redis();
$redis->connect('127.0.0.1', 6379, 1.0);

$startDepth = (int) $redis->lLen('siro:queue:default');
$startTime = microtime(true);
$deadline = $startTime + 172800;
$processed = 0;
$failed = 0;
$lastLogTime = $startTime;

error_log("[worker] Started at " . date('Y-m-d H:i:s') . " | start_depth=$startDepth | deadline=" . date('Y-m-d H:i:s', (int)$deadline));

// Quick test: pop one job and inspect it
$testRaw = $redis->brPop(['siro:queue:default'], 1);
if ($testRaw) {
    error_log("[worker] TEST POP: " . substr($testRaw[1], 0, 200));
    $testDecoded = json_decode($testRaw[1], true);
    if ($testDecoded) {
        error_log("[worker] TEST JOB: class=" . ($testDecoded['job'] ?? 'null'));
        error_log("[worker] TEST DATA: " . json_encode($testDecoded['data'] ?? []));
        $testClass = $testDecoded['job'] ?? '';
        error_log("[worker] TEST CLASS_EXISTS: " . (class_exists($testClass) ? 'yes' : 'no'));
        if (class_exists($testClass)) {
            error_log("[worker] TEST HAS_HANDLE: " . (method_exists($testClass, 'handle') ? 'yes' : 'no'));
        }
    }
    // Don't count this test job
}

while (microtime(true) < $deadline) {
    // Use brPop with 3s timeout — matches framework behavior
    $result = $redis->brPop(['siro:queue:default'], 3);

    if ($result === false || $result === null) {
        // Queue empty — backoff
        usleep(50000);
        continue;
    }

    $raw = $result[1];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $failed++;
        if ($failed <= 10) {
            error_log("[worker] SKIP: non-JSON payload: " . substr($raw, 0, 100));
        }
        continue;
    }

    $jobClass = $decoded['job'] ?? '';
    $jobData = $decoded['data'] ?? [];

    $success = false;
    $errorMsg = null;

    try {
        if ($jobClass === '') {
            $errorMsg = 'Empty job class';
        } elseif (!class_exists($jobClass)) {
            $errorMsg = "Class '{$jobClass}' not found";
        } elseif (!method_exists($jobClass, 'handle')) {
            $errorMsg = "Class '{$jobClass}' has no handle() method";
        } else {
            $instance = new $jobClass();
            $instance->handle(is_array($jobData) ? $jobData : []);
            $success = true;
        }
    } catch (\Throwable $e) {
        $errorMsg = get_class($e) . ': ' . $e->getMessage();
    }

    if ($success) {
        $processed++;
    } else {
        $failed++;
        if ($failed <= 20) {
            error_log("[worker] FAIL: job={$jobClass} error={$errorMsg}");
        }
    }

    // Log every 60 seconds
    $now = microtime(true);
    if ($now - $lastLogTime >= 60) {
        $currentDepth = (int) $redis->lLen('siro:queue:default');
        $elapsed = round($now - $startTime);
        $rate = $processed > 0 ? round($processed / ($now - $startTime), 1) : 0;
        $mem = round(memory_get_usage(true) / 1024);

        error_log(sprintf(
            "[worker] %s | elapsed=%ds | processed=%d | failed=%d | depth=%d | rate=%.1f/s | mem=%dKB",
            date('H:i:s'), $elapsed, $processed, $failed, $currentDepth, $rate, $mem
        ));

        $lastLogTime = $now;
    }
}

$endDepth = (int) $redis->lLen('siro:queue:default');
$duration = round(microtime(true) - $startTime);
error_log(sprintf(
    "[worker] COMPLETED | duration=%ds | processed=%d | failed=%d | start_depth=%d end_depth=%d",
    $duration, $processed, $failed, $startDepth, $endDepth
));

$redis->close();
