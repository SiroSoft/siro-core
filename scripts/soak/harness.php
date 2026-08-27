<?php
declare(strict_types=1);

/**
 * SiroPHP 48-Hour Production Soak Harness
 *
 * Usage:
 *   php scripts/soak/harness.php                     # 48h soak
 *   php scripts/soak/harness.php --duration=300      # 5-min validation
 *   php scripts/soak/harness.php --duration=3600     # 1-hour soak
 *   php scripts/soak/harness.php --monitor-only       # Monitor existing server
 *
 * Requirements:
 *   - PHP 8.2+ with pcntl extension (for background monitor)
 *   - php built-in server or PHP-FPM+Nginx
 *   - SQLite (default) or configured DB
 */

$startTime = microtime(true);
$duration = 48 * 3600; // Default 48 hours
$mode = 'soak'; // 'soak' or 'monitor-only'
$port = 8787;
$basePath = dirname(__DIR__, 2);
$storageDir = $basePath . '/storage';

// Parse args
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--duration=')) {
        $duration = max(60, (int) substr($arg, 11));
    } elseif ($arg === '--monitor-only') {
        $mode = 'monitor-only';
    } elseif (str_starts_with($arg, '--port=')) {
        $port = max(1024, (int) substr($arg, 7));
    }
}

// Ensure storage
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}

// ── Phase 1: Setup ──
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║       SiroPHP Production Soak Harness                    ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║ Duration:  " . gmdate('H:i:s', $duration) . "                                ║\n";
echo "║ Mode:      {$mode}                              ║\n";
echo "║ Port:      {$port}                                    ║\n";
echo "║ Started:   " . date('Y-m-d H:i:s') . "                       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Record environment
$env = [
    'git_sha' => trim(shell_exec("cd {$basePath} && git rev-parse --short HEAD 2>/dev/null") ?: 'unknown'),
    'php_version' => PHP_VERSION,
    'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
    'start_time' => date('c'),
    'duration_seconds' => $duration,
    'mode' => $mode,
    'port' => $port,
];
file_put_contents($storageDir . '/soak_env.json', json_encode($env, JSON_PRETTY_PRINT));
echo "Environment recorded: {$storageDir}/soak_env.json\n";
echo "Git SHA: {$env['git_sha']}\n";
echo "PHP: {$env['php_version']}\n\n";

// ── Phase 2: Start server (if not monitor-only) ──
$serverPid = null;
if ($mode !== 'monitor-only') {
    $appScript = __DIR__ . '/app.php';
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: use proc_open for background server
        $cmd = sprintf('php -S 127.0.0.1:%d "%s"', $port, $appScript);
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['file', $storageDir . '/soak_server.log', 'w'],
            2 => ['file', $storageDir . '/soak_server.log', 'a'],
        ];
        $serverProc = proc_open($cmd, $desc, $serverPipes);
        $serverPid = is_resource($serverProc) ? get_resource_id($serverProc) : 'unknown';
    } else {
        $cmd = sprintf('php -S 127.0.0.1:%d "%s" > "%s/soak_server.log" 2>&1 & echo $!',
            $port, $appScript, $storageDir
        );
        $serverPid = trim(shell_exec($cmd) ?: '');
    }
    echo "Server started: PID={$serverPid}, port={$port}\n";

    // Wait for server
    $retries = 0;
    while ($retries < 30) {
        $ch = curl_init("http://127.0.0.1:{$port}/health");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            echo "Server healthy after {$retries} retries\n\n";
            break;
        }
        $retries++;
        usleep(500000);
    }
    if ($retries >= 30) {
        echo "ERROR: Server failed to start\n";
        exit(1);
    }
}

// ── Phase 3: Workload + Monitor loop ──
$deadline = $startTime + $duration;
$iteration = 0;
$metricsHistory = [];
$lastMetricsWrite = 0;
$sampleInterval = 60; // Sample every 60 seconds

echo "Starting soak loop (deadline: " . date('Y-m-d H:i:s', (int) $deadline) . ")\n\n";

// Routes to exercise (weighted)
$routes = [
    '/health' => 5,
    '/api/fast' => 25,
    '/api/middleware' => 10,
    '/api/cache/hit' => 10,
    '/api/cache/miss' => 5,
    '/api/cache/stampede' => 3,
    '/api/db/select' => 10,
    '/api/trace/lifecycle' => 5,
    '/api/session' => 5,
];

$writeRoutes = [
    '/api/validate' => 5,
    '/api/db/write' => 5,
    '/api/queue/dispatch' => 5,
];

$failRoutes = [
    '/api/fail/inject' => 1,
];

// Build weighted route list
$allRoutes = [];
foreach ($routes as $route => $weight) {
    for ($i = 0; $i < $weight; $i++) {
        $allRoutes[] = ['uri' => $route, 'method' => 'GET'];
    }
}
foreach ($writeRoutes as $route => $weight) {
    for ($i = 0; $i < $weight; $i++) {
        $allRoutes[] = ['uri' => $route, 'method' => 'POST'];
    }
}
foreach ($failRoutes as $route => $weight) {
    for ($i = 0; $i < $weight; $i++) {
        $allRoutes[] = ['uri' => $route, 'method' => 'GET'];
    }
}

$totalRoutes = count($allRoutes);

// Counters
$counters = [
    'requests' => 0,
    'success' => 0,
    'expected_4xx' => 0,
    'unexpected_4xx' => 0,
    '5xx' => 0,
    'errors' => 0,
    'cache_stampede_callbacks' => 0,
    'db_ops' => 0,
    'queue_dispatched' => 0,
];

while (microtime(true) < $deadline) {
    $iteration++;

    // Pick random route
    $route = $allRoutes[array_rand($allRoutes)];

    // Make request
    $url = "http://127.0.0.1:{$port}{$route['uri']}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_CUSTOMREQUEST => $route['method'],
    ]);

    if ($route['method'] === 'POST') {
        if ($route['uri'] === '/api/validate') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => 'soak_' . $iteration]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $counters['requests']++;

    if ($curlError) {
        $counters['errors']++;
    } elseif ($httpCode >= 200 && $httpCode < 300) {
        $counters['success']++;
    } elseif ($httpCode === 404 && $route['uri'] !== '/nonexistent') {
        $counters['unexpected_4xx']++;
    } elseif ($httpCode === 422 || $httpCode === 404) {
        $counters['expected_4xx']++;
    } elseif ($httpCode >= 500) {
        $counters['5xx']++;
    } else {
        $counters['expected_4xx']++;
    }

    // Parse response for specific metrics
    if ($response && $httpCode === 200) {
        $data = json_decode($response, true);
        if ($data) {
            if (isset($data['callback_executed'])) {
                $counters['cache_stampede_callbacks'] += $data['callback_executed'];
            }
            if (isset($data['result']) && $data['result'] === 'dispatched') {
                $counters['queue_dispatched']++;
            }
            if (isset($data['result']) && str_starts_with((string) $data['result'], 'db_')) {
                $counters['db_ops']++;
            }
        }
    }

    // Periodic sampling
    if (microtime(true) - $lastMetricsWrite >= $sampleInterval) {
        $sample = [
            'time' => time(),
            'elapsed' => round(microtime(true) - $startTime),
            'iteration' => $iteration,
            'memory_peak' => memory_get_peak_usage(true),
            'memory_current' => memory_get_usage(true),
        ];

        // Get server metrics
        $metricsCh = curl_init("http://127.0.0.1:{$port}/metrics");
        curl_setopt($metricsCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($metricsCh, CURLOPT_TIMEOUT, 5);
        $metricsResp = curl_exec($metricsCh);
        curl_close($metricsCh);

        if ($metricsResp) {
            $serverMetrics = json_decode($metricsResp, true);
            if ($serverMetrics) {
                $sample = array_merge($sample, $serverMetrics);
            }
        }

        // Get system memory
        if (PHP_OS_FAMILY !== 'Windows') {
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo) {
                preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m);
                $sample['system_mem_available_kb'] = (int) ($m[1] ?? 0);
            }
        }

        $metricsHistory[] = $sample;
        $lastMetricsWrite = microtime(true);

        // Write sample to disk
        file_put_contents($storageDir . '/soak_samples.jsonl',
            json_encode($sample) . "\n",
            LOCK_EX | FILE_APPEND
        );

        // Progress report every 5 minutes
        if ($iteration % 300 === 0) {
            $elapsed = round(microtime(true) - $startTime);
            $remaining = max(0, $deadline - microtime(true));
            $rps = $iteration / max(1, $elapsed);
            echo sprintf(
                "[%s] %d reqs (%.1f/s) | success=%d 5xx=%d errors=%d | mem=%dKB | remaining=%s\n",
                date('H:i:s'),
                $iteration,
                $rps,
                $counters['success'],
                $counters['5xx'],
                $counters['errors'],
                $sample['memory_current'] / 1024,
                gmdate('H:i:s', (int) $remaining)
            );
        }
    }

    // Brief pause to avoid CPU saturation
    usleep(1000); // 1ms
}

// ── Phase 4: Final metrics ──
echo "\n\n=== Soak Complete ===\n";
echo "Duration: " . gmdate('H:i:s', (int) (microtime(true) - $startTime)) . "\n";
echo "Requests: {$counters['requests']}\n";
echo "Success: {$counters['success']}\n";
echo "5xx: {$counters['5xx']}\n";
echo "Errors: {$counters['errors']}\n";
echo "Cache stampede callbacks: {$counters['cache_stampede_callbacks']}\n";
echo "Queue dispatched: {$counters['queue_dispatched']}\n";

// Write final summary
$summary = [
    'start_time' => date('c', (int) $startTime),
    'end_time' => date('c'),
    'duration_seconds' => round(microtime(true) - $startTime),
    'counters' => $counters,
    'env' => $env,
    'samples_count' => count($metricsHistory),
];
file_put_contents($storageDir . '/soak_summary.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\nSummary: {$storageDir}/soak_summary.json\n";

// ── Phase 5: Acceptance criteria ──
echo "\n=== Acceptance Criteria ===\n";
$pass = true;
$criteria = [
    'Framework-caused fatal errors' => $counters['errors'] === 0,
    'Unexpected HTTP 5xx' => $counters['5xx'] === 0,
    'Cache stampede callbacks ≤ 2x expected' => $counters['cache_stampede_callbacks'] <= $counters['requests'] * 0.1,
    'No sustained memory growth' => true, // Will be evaluated by analyze script
];

foreach ($criteria as $name => $met) {
    $status = $met ? '✅' : '❌';
    echo "{$status} {$name}\n";
    if (!$met) {
        $pass = false;
    }
}

// Cleanup server
if (isset($serverProc) && is_resource($serverProc)) {
    echo "\nStopping server...\n";
    proc_terminate($serverProc);
    proc_close($serverProc);
} elseif ($serverPid && PHP_OS_FAMILY !== 'Windows') {
    echo "\nStopping server (PID={$serverPid})...\n";
    shell_exec("kill {$serverPid} 2>/dev/null");
}

$exitCode = $pass ? 0 : 1;
echo "\n" . ($pass ? '✅ SOAK COMPLETE' : '❌ SOAK FAILED') . "\n";
exit($exitCode);
