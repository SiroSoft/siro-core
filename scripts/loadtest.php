#!/usr/bin/env php
<?php
declare(strict_types=1);

$url = $argv[1] ?? 'http://localhost:8080';
$requests = max(1, (int) ($argv[2] ?? 100));
$concurrency = max(1, (int) ($argv[3] ?? 10));

echo "=== Siro Production Load Test ===\n";
echo "Target:      $url\n";
echo "Requests:    $requests\n";
echo "Concurrency: $concurrency\n\n";

$isWindows = DIRECTORY_SEPARATOR === '\\';
$findCmd = $isWindows ? 'where ab 2>nul' : 'which ab 2>/dev/null';
$abBin = trim((string) @shell_exec($findCmd));

if ($abBin !== '') {
    echo "Using Apache Bench...\n";
    $escapedUrl = escapeshellarg($url);
    passthru(escapeshellcmd($abBin) . " -n $requests -c $concurrency -k $escapedUrl", $exitCode);
    exit($exitCode);
}

echo "[INFO] Apache Bench (ab) not found. Falling back to curl-based sequential test...\n\n";

$totalTime = 0.0;
$success = 0;
$failed = 0;
$times = [];

for ($i = 0; $i < $requests; $i++) {
    $start = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['User-Agent: Siro-LoadTest/1.0'],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $elapsed = (microtime(true) - $start) * 1000;
    curl_close($ch);

    $times[] = $elapsed;
    $totalTime += $elapsed;

    if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
        $success++;
    } else {
        $failed++;
    }
}

$avg = $totalTime / max(1, $requests);
sort($times);
$p50 = $times[(int) floor($requests * 0.50)] ?? 0;
$p95 = $times[(int) floor($requests * 0.95)] ?? 0;
$p99 = $times[(int) floor($requests * 0.99)] ?? 0;
$rps = $requests / max(0.001, $totalTime / 1000);

echo "--- Results ---\n";
echo "Successful:    $success\n";
echo "Failed:        $failed\n";
echo "Total time:    " . round($totalTime / 1000, 2) . "s\n";
echo "Requests/sec:  " . round($rps, 0) . "\n";
echo "Avg latency:   " . round($avg, 2) . " ms\n";
echo "P50 latency:   " . round($p50, 2) . " ms\n";
echo "P95 latency:   " . round($p95, 2) . " ms\n";
echo "P99 latency:   " . round($p99, 2) . " ms\n";

if ($failed > 0) {
    echo "\n[!] Load test failures detected. Review before production deployment.\n";
    exit(1);
}

if ($p95 > 500) {
    echo "\n[!] High latency detected (P95 > 500ms). Consider performance optimization.\n";
}

echo "\n[OK] Load test passed.\n";
exit(0);
