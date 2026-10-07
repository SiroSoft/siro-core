<?php
declare(strict_types=1);

/**
 * SiroPHP Focused Soak — Redis Queue Worker (v2)
 *
 * Uses framework's Queue::work() which handles Redis BRPOP internally.
 * Records execution in JSONL for verification.
 * Exits cleanly at deadline.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';
require_once $basePath . '/scripts/soak/SoakTestJob.php';

// Boot the app so .env is loaded and QUEUE_DRIVER/CACHE_DRIVER are set
$workerApp = new \Siro\Core\App($basePath);
$workerApp->boot();

\Siro\Core\Queue::registerJob(\Soak\SoakTestJob::class);

$deadline = $argv[1] ?? '';
if ($deadline === '') {
    $deadline = time() + 3900;
} elseif (!is_numeric($deadline)) {
    $deadline = (int)strtotime($deadline);
} else {
    $deadline = (int)$deadline;
}

$logFile = $basePath . '/storage/soak_queue_log.jsonl';
$workerLog = $basePath . '/storage/soak_worker.log';

function wlog(string $msg): void
{
    global $workerLog;
    $line = date('Y-m-d H:i:s') . " [WORKER] " . $msg . "\n";
    @file_put_contents($workerLog, $line, LOCK_EX | FILE_APPEND);
    echo $line;
}

wlog("Starting queue worker (v2). Deadline: " . date('Y-m-d H:i:s', $deadline));
wlog("PID: " . getmypid());

$processed = 0;
$failed = 0;
$startTime = time();
$emptyCycles = 0;

while (true) {
    if (time() >= $deadline) {
        wlog("Deadline reached. Processed={$processed} Failed={$failed} Duration=" . (time() - $startTime) . "s");
        break;
    }

    try {
        $worked = \Siro\Core\Queue::work();
        if ($worked) {
            $processed++;
            $emptyCycles = 0;
            // Log to JSONL
            @file_put_contents($logFile, json_encode([
                'timestamp' => time(),
                'job_id' => 'soak_' . $processed,
                'processing_delay' => 0,
                'memory' => memory_get_usage(true),
            ]) . "\n", LOCK_EX | FILE_APPEND);
        } else {
            $emptyCycles++;
            if ($emptyCycles % 10 === 0) {
                wlog("Idle cycles: {$emptyCycles} | processed={$processed} failed={$failed}");
            }
            usleep(100000); // 100ms sleep when no jobs
        }
    } catch (\Throwable $e) {
        $failed++;
        if ($failed % 100 === 0) {
            wlog("ERROR ({$failed}): " . $e->getMessage());
        }
    }
}

wlog("Worker finished. processed={$processed} failed={$failed} total=" . ($processed + $failed));
