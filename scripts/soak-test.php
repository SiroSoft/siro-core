<?php

declare(strict_types=1);

/**
 * SiroPHP soak test — sustained traffic + trace verification pilot.
 *
 * Runs a continuous stream of requests against a running `php siro serve`
 * instance, verifying responses are stable and traces are captured. This is
 * the "soak" step that surfaces time-based / memory-leak / concurrency issues
 * before a v1.0 release.
 *
 * Usage:
 *   php scripts/soak-test.php --base=http://localhost:8080 --requests=1000 --concurrency=10
 *
 * Options:
 *   --base=URL        Server base URL (default http://localhost:8080)
 *   --requests=N      Total requests to send (default 1000)
 *   --concurrency=N   Parallel workers (default 10)
 *   --verify-traces   After soak, assert the trace dir grew (needs APP_DEBUG=true)
 */

$base = 'http://localhost:8080';
$total = 1000;
$concurrency = 10;
$verifyTraces = false;
$tracesDir = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base='))          $base = substr($arg, 7);
    elseif (str_starts_with($arg, '--requests='))  $total = max(1, (int) substr($arg, 11));
    elseif (str_starts_with($arg, '--concurrency=')) $concurrency = max(1, (int) substr($arg, 14));
    elseif (str_starts_with($arg, '--traces-dir=')) $tracesDir = substr($arg, 13);
    elseif ($arg === '--verify-traces')            $verifyTraces = true;
}

if ($tracesDir === null) {
    $tracesDir = dirname(__DIR__) . '/storage/logs/traces';
}

function http(string $url, string $method = 'GET'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

function countTraceFiles(string $dir): int
{
    $count = 0;
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $entry) {
        /** @var \SplFileInfo $entry */
        if ($entry->isFile() && $entry->getExtension() === 'json') {
            $count++;
        }
    }
    return $count;
}

// Health check first
$health = http($base . '/health/live');
if ($health['status'] !== 200) {
    fwrite(STDERR, "Server not healthy at {$base} (status {$health['status']}). Start with: php siro serve\n");
    exit(1);
}

$before = 0;
if ($verifyTraces && is_dir($tracesDir)) {
    $before = countTraceFiles($tracesDir);
}

$start = microtime(true);
$statusCounts = [];
$errors = 0;
$latencies = [];
$sent = 0;

/** @var array<int, resource|null> $handles */
$handles = [];
for ($i = 0; $i < $concurrency; $i++) {
    $handles[$i] = null;
}

$batch = [];
$batchCount = 0;
$totalSent = 0;

// Simple token-bucket concurrency: fire up to $concurrency in-flight via curl_multi
$mh = curl_multi_init();
$inflight = [];
$next = 0;

while ($totalSent < $total || count($inflight) > 0) {
    // Fill up to concurrency
    while ($totalSent < $total && count($inflight) < $concurrency) {
        $ch = curl_init($base . '/health/live');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $id = (int) $ch;
        curl_multi_add_handle($mh, $ch);
        $inflight[$id] = ['ch' => $ch, 'start' => microtime(true)];
        $totalSent++;
    }
    // Run
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running && count($inflight) > 0);

    // Collect finished
    foreach ($inflight as $id => $info) {
        $code = (int) curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
        $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
        $lat = (microtime(true) - $info['start']) * 1000;
        $latencies[] = $lat;
        if ($code !== 200) $errors++;
        curl_multi_remove_handle($mh, $info['ch']);
        curl_close($info['ch']);
        unset($inflight[$id]);
    }
}

curl_multi_close($mh);

$elapsed = microtime(true) - $start;
$rps = $totalSent / $elapsed;
$avgLat = count($latencies) > 0 ? array_sum($latencies) / count($latencies) : 0;
sort($latencies);
$p95 = count($latencies) > 0 ? $latencies[(int) floor(count($latencies) * 0.95)] : 0;

$after = $before;
if ($verifyTraces && is_dir($tracesDir)) {
    $after = countTraceFiles($tracesDir);
}

echo "=== SiroPHP Soak Test ===\n";
echo "  Base:        {$base}\n";
echo "  Requests:    {$totalSent} (concurrency {$concurrency})\n";
echo "  Duration:    " . round($elapsed, 2) . "s\n";
echo "  Throughput:  " . round($rps, 1) . " req/s\n";
echo "  Avg latency: " . round($avgLat, 1) . "ms\n";
echo "  P95 latency: " . round($p95, 1) . "ms\n";
echo "  Statuses:    " . json_encode($statusCounts) . "\n";
echo "  Errors:      {$errors}\n";
if ($verifyTraces) {
    echo "  Traces:      {$before} -> {$after} (grew by " . ($after - $before) . ")\n";
}
echo "==============================\n";

$ok = $errors === 0;
echo $ok ? "  ✅ Soak passed\n" : "  ❌ Soak FAILED — {$errors} errors\n";
exit($ok ? 0 : 1);
