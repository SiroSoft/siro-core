<?php
declare(strict_types=1);
$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

// Load .env
if (file_exists($basePath . '/.env')) {
    foreach (file($basePath . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (trim($line) === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
}

echo "QUEUE_DRIVER=" . ($_ENV['QUEUE_DRIVER'] ?? 'db') . "\n";
echo "CACHE_DRIVER=" . ($_ENV['CACHE_DRIVER'] ?? 'file') . "\n";

Siro\Core\Database::configure([
    'driver' => 'sqlite',
    'database' => $basePath . '/storage/soak.sqlite',
]);
Siro\Core\Queue::registerJob(Soak\SoakTestJob::class);

echo "Pushing 1 job via Queue::push()...\n";
Siro\Core\Queue::push(Soak\SoakTestJob::class, [
    'id' => 'b4_test_1',
    'dispatched_at' => time(),
]);
echo "Depth after push: " . Siro\Core\Queue::pendingCount() . "\n";

echo "Running Queue::work()...\n";
$result = Siro\Core\Queue::work();
echo "Work returned: " . ($result ? 'true' : 'false') . "\n";
echo "Depth after work: " . Siro\Core\Queue::pendingCount() . "\n";

// Check the log file
$logFile = $basePath . '/storage/soak_queue_log.jsonl';
if (file_exists($logFile)) {
    $lines = array_filter(explode("\n", file_get_contents($logFile)));
    if ($lines) {
        $last = end($lines);
        $decoded = json_decode($last, true);
        echo "Last log entry: " . json_encode($decoded) . "\n";
        echo "B4 SMOKE: PASS\n";
    } else {
        echo "B4 SMOKE: FAIL - no log entries\n";
    }
} else {
    echo "B4 SMOKE: FAIL - no log file\n";
}
