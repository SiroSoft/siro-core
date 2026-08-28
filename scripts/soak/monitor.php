<?php
declare(strict_types=1);

/**
 * External Process Monitor for Soak Test
 *
 * Runs as a separate process. Monitors PHP-FPM master/worker RSS,
 * system memory, and disk usage.
 *
 * Usage:
 *   php scripts/soak/monitor.php [--interval=30] [--output=storage/soak_process.jsonl]
 *
 * Requires Linux (/proc filesystem).
 * On Windows/macOS, outputs basic metrics only.
 */

$interval = 30;
$outputFile = dirname(__DIR__, 2) . '/storage/soak_process.jsonl';
$duration = 48 * 3600;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $interval = max(5, (int) substr($arg, 11));
    } elseif (str_starts_with($arg, '--output=')) {
        $outputFile = substr($arg, 9);
    } elseif (str_starts_with($arg, '--duration=')) {
        $duration = max(60, (int) substr($arg, 11));
    }
}

$startTime = time();
$deadline = $startTime + $duration;

echo "Monitor started: interval={$interval}s, output={$outputFile}\n";

function getPhpFpmProcesses(): array
{
    $processes = [];
    $output = [];

    // Find PHP-FPM master
    exec('ps aux 2>/dev/null | grep -E "php-fpm|php-cgi|php.*pool" | grep -v grep', $output);

    foreach ($output as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 11) {
            $processes[] = [
                'pid' => (int) $parts[1],
                'cpu' => (float) $parts[2],
                'mem_percent' => (float) $parts[3],
                'rss_kb' => (int) $parts[5],
                'command' => implode(' ', array_slice($parts, 10)),
            ];
        }
    }

    return $processes;
}

function getSystemMemory(): array
{
    $memInfo = @file_get_contents('/proc/meminfo');
    if (!$memInfo) {
        return ['available_kb' => 0, 'total_kb' => 0, 'used_kb' => 0];
    }

    $total = 0;
    $available = 0;
    if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) {
        $total = (int) $m[1];
    }
    if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) {
        $available = (int) $m[1];
    }

    return [
        'total_kb' => $total,
        'available_kb' => $available,
        'used_kb' => $total - $available,
    ];
}

function getDiskUsage(string $path): array
{
    $stat = @disk_free_space($path);
    $total = @disk_total_space($path);
    return [
        'free_bytes' => $stat ? (int) $stat : 0,
        'total_bytes' => $total ? (int) $total : 0,
        'used_bytes' => $total && $stat ? (int) ($total - $stat) : 0,
    ];
}

function countOpenFileDescriptors(int $pid): int
{
    $dir = "/proc/{$pid}/fd";
    if (!is_dir($dir)) {
        return -1;
    }
    $count = 0;
    $items = @scandir($dir);
    if ($items) {
        $count = count($items) - 2; // Remove . and ..
    }
    return max(0, $count);
}

while (time() < $deadline) {
    $sample = [
        'timestamp' => date('c'),
        'epoch' => time(),
    ];

    // PHP-FPM processes
    $fpmProcesses = getPhpFpmProcesses();
    $sample['fpm_process_count'] = count($fpmProcesses);
    $sample['fpm_total_rss_kb'] = array_sum(array_column($fpmProcesses, 'rss_kb'));

    if (!empty($fpmProcesses)) {
        $sample['fpm_max_rss_kb'] = max(array_column($fpmProcesses, 'rss_kb'));
        $sample['fpm_avg_rss_kb'] = (int) (array_sum(array_column($fpmProcesses, 'rss_kb')) / count($fpmProcesses));
    }

    // System memory
    $sample['system'] = getSystemMemory();

    // Disk
    $sample['disk'] = getDiskUsage(dirname($outputFile));

    // File descriptors for master process
    if (!empty($fpmProcesses)) {
        $masterPid = $fpmProcesses[0]['pid'];
        $sample['master_fd_count'] = countOpenFileDescriptors($masterPid);
    }

    // Write sample
    file_put_contents($outputFile, json_encode($sample) . "\n", LOCK_EX | FILE_APPEND);

    // Console output every 10 samples
    static $consoleCount = 0;
    $consoleCount++;
    if ($consoleCount % 10 === 0) {
        echo sprintf(
            "[%s] FPM workers: %d | RSS: %dKB | System free: %dMB\n",
            date('H:i:s'),
            $sample['fpm_process_count'],
            $sample['fpm_total_rss_kb'] ?? 0,
            ($sample['system']['available_kb'] ?? 0) / 1024
        );
    }

    sleep($interval);
}

echo "Monitor stopped after " . gmdate('H:i:s', time() - $startTime) . "\n";
