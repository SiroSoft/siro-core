<?php
declare(strict_types=1);

/**
 * Cache stampede worker — spawned by cache-stampede-test.php
 *
 * Usage: php cache-stampede-worker.php <counter_file> <cache_dir> <key> <worker_id>
 *
 * Each worker calls Cache::remember() on the same key.
 * The callback increments a shared counter file atomically.
 * We record how many workers executed the callback.
 */

$counterFile = $argv[1] ?? die("Usage: worker.php <counter_file> <cache_dir> <key> <worker_id>\n");
$cacheDir = $argv[2] ?? die("Missing cache_dir\n");
$key = $argv[3] ?? die("Missing key\n");
$workerId = $argv[4] ?? die("Missing worker_id\n");

// Boot SiroPHP autoloader and cache
$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

// Boot cache with explicit path
\Siro\Core\Cache::boot($basePath);
// Override cache dir for isolation
$reflection = new ReflectionClass(\Siro\Core\Cache::getInstance());
$prop = $reflection->getProperty('driver');
$prop->setValue(\Siro\Core\Cache::getInstance(), new \Siro\Core\Cache\Drivers\FileDriver($cacheDir));

// Shared computation counter file
$counterPath = $counterFile . '_computation_count';

$result = \Siro\Core\Cache::remember($key, 30, function () use ($counterPath, $workerId) {
    // Record that THIS worker executed the callback
    $logFile = $counterPath . '_log';
    file_put_contents($logFile, $workerId . "\n", LOCK_EX | FILE_APPEND);

    // Increment computation counter atomically
    $count = 0;
    $fp = fopen($counterPath, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $count = (int) fread($fp, 1024);
        $count++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $count);
        fclose($fp);
    }

    // Simulate expensive computation
    usleep(50000); // 50ms

    return "result_from_{$workerId}";
});

// Output result for this worker
echo json_encode([
    'worker_id' => $workerId,
    'result' => $result,
]) . "\n";
