<?php
declare(strict_types=1);

/**
 * SiroPHP Focused Soak — Harness
 *
 * Hits all endpoints in round-robin and writes metrics.jsonl.
 * Expected duration: 60 minutes.
 */

$target = 'http://127.0.0.1:8088';
$duration = 3600; // 60 minutes
$metricsFile = __DIR__ . '/../storage/metrics-focused.jsonl';

// Parse args
foreach ($argv as $i => $arg) {
    if ($arg === '--target' && isset($argv[$i + 1])) $target = $argv[$i + 1];
    if ($arg === '--duration' && isset($argv[$i + 1])) $duration = (int)$argv[$i + 1];
}

$startTime = time();
$endTime = $startTime + $duration;

echo "=== Focused Soak Harness ===\n";
echo "Target: {$target}\n";
echo "Duration: {$duration}s\n";
echo "Started: " . date('c') . "\n\n";

// Clear old metrics
file_put_contents($metricsFile, '');

$endpoints = [
    // GET endpoints
    ['GET', '/health', null],
    ['GET', '/api/fast', null],
    ['GET', '/api/middleware', null],
    ['GET', '/api/cache/hit', null],
    ['GET', '/api/cache/miss', null],
    ['GET', '/api/cache/stampede', null],
    ['GET', '/api/db/select', null],
    ['GET', '/api/trace/lifecycle', null],
    ['GET', '/api/session', null],
    // POST endpoints
    ['POST', '/api/validate', json_encode(['name' => 'soak_test', 'email' => 'soak@test.local', 'value' => 42])],
    ['POST', '/api/validate', json_encode(['name' => '', 'email' => 'bad', 'value' => -1])], // validation error
    ['POST', '/api/db/write', json_encode(['name' => 'soak_write', 'value' => mt_rand(1, 1000)])],
    ['POST', '/api/queue/dispatch', json_encode([
        'id' => 'soak_' . mt_rand(1, 999999),
        'dispatched_at' => time(),
        'to' => 'soak@test.local',
        'subject' => 'soak-test-' . mt_rand(1, 999999),
        'body' => 'Production soak queue test',
    ])],
    // Fail injection
    ['GET', '/api/fail/inject', null],
];

$totalRequests = 0;
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);

while (time() < $endTime) {
    foreach ($endpoints as [$method, $route, $body]) {
        if (time() >= $endTime) break;

        $url = $target . $route;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, null);
        }

        $start = microtime(true);
        $response = curl_exec($ch);
        $elapsed = round((microtime(true) - $start) * 1000, 3);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $data = null;
        if ($response !== false) {
            $data = json_decode($response, true);
        }

        $metric = [
            'type' => 'request',
            'route' => $route,
            'method' => $method,
            'status' => $httpCode,
            'duration_ms' => $elapsed,
            'time' => date('c'),
            'timestamp' => microtime(true),
            'pid' => getmypid(),
            'data' => $data,
        ];

        if ($error) {
            $metric['error'] = $error;
        }

        // Memory
        $metric['memory'] = memory_get_usage(true);

        file_put_contents($metricsFile, json_encode($metric) . "\n", LOCK_EX | FILE_APPEND);

        $totalRequests++;
        if ($totalRequests % 100 === 0) {
            $elapsed_so_far = time() - $startTime;
            $remaining = $endTime - time();
            printf("\r[%ds] Requests: %d | Last: %s %s → %d | Remaining: %ds   ",
                $elapsed_so_far, $totalRequests, $method, $route, $httpCode, $remaining);
        }
    }
}

curl_close($ch);

$totalTime = time() - $startTime;
echo "\n\n=== Harness Complete ===\n";
echo "Total requests: {$totalRequests}\n";
echo "Duration: {$totalTime}s\n";
echo "Ended: " . date('c') . "\n";
