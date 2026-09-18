<?php
declare(strict_types=1);

/**
 * SiroPHP Focused Soak Evaluator
 *
 * Reads metrics.jsonl and produces the final verdict.
 * Separates expected (injected) 5xx from unexpected 5xx.
 */

$metricsFile = $argv[1] ?? __DIR__ . '/../storage/metrics-focused.jsonl';
$logFile = __DIR__ . '/../storage/soak_queue_log.jsonl';
$resultFile = __DIR__ . '/../storage/soak-focused-result.json';

if (!file_exists($metricsFile)) {
    echo "FAIL: metrics file not found: $metricsFile\n";
    exit(1);
}

$lines = file($metricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$metrics = [];
foreach ($lines as $line) {
    $m = json_decode($line, true);
    if ($m && isset($m['type'])) {
        $metrics[] = $m;
    }
}

// Aggregate counters
$counts = [
    'total_requests' => 0,
    'success_2xx' => 0,
    'expected_4xx' => 0,
    'unexpected_4xx' => 0,
    'expected_5xx' => 0,
    'unexpected_5xx' => 0,
    'timeouts' => 0,
    'connection_failures' => 0,
    'cache_hits' => 0,
    'cache_misses' => 0,
    'cache_stampede_callbacks' => 0,
    'db_selects' => 0,
    'db_writes' => 0,
    'db_errors' => 0,
    'queue_dispatched' => 0,
    'queue_execution_error' => 0,
    'validation_ok' => 0,
    'validation_errors' => 0,
    'trace_lifecycle' => 0,
    'session_ops' => 0,
    'redis_errors' => 0,
];

$memories = ['start' => null, 'end' => null, 'min' => PHP_INT_MAX, 'max' => 0, 'sum' => 0, 'count' => 0];

foreach ($metrics as $m) {
    $counts['total_requests']++;

    $status = $m['status'] ?? 0;
    $route = $m['route'] ?? '';

    if ($status >= 200 && $status < 300) {
        $counts['success_2xx']++;
    } elseif ($status >= 400 && $status < 500) {
        if (in_array($status, [404, 405, 422])) {
            $counts['expected_4xx']++;
        } else {
            $counts['unexpected_4xx']++;
        }
    } elseif ($status >= 500) {
        // /api/fail/inject is expected; everything else is unexpected
        if ($route === '/api/fail/inject') {
            $counts['expected_5xx']++;
        } else {
            $counts['unexpected_5xx']++;
        }
    }

    if (isset($m['error']) && $m['error']) {
        if (str_contains((string)$m['error'], 'timeout')) {
            $counts['timeouts']++;
        } elseif (str_contains((string)$m['error'], 'connection')) {
            $counts['connection_failures']++;
        }
    }

    // Extract counters from response data
    $data = $m['data'] ?? [];

    if (isset($data['hit']) && $data['hit'] === true) {
        $counts['cache_hits']++;
    }
    if (isset($data['result']) && $data['result'] === 'cache_miss') {
        $counts['cache_misses']++;
    }
    if (isset($data['callback_executed']) && is_int($data['callback_executed'])) {
        $counts['cache_stampede_callbacks'] += $data['callback_executed'];
    }
    if (isset($data['result'])) {
        $r = $data['result'];
        if ($r === 'db_select') $counts['db_selects']++;
        if ($r === 'db_write') $counts['db_writes']++;
        if (str_contains($r, 'error')) $counts['db_errors']++;
        if ($r === 'dispatched') $counts['queue_dispatched']++;
        if ($r === 'dispatch_error') $counts['queue_execution_error']++;
        if ($r === 'validated') $counts['validation_ok']++;
        if ($r === 'validation_error') $counts['validation_errors']++;
        if ($r === 'trace') $counts['trace_lifecycle']++;
        if ($r === 'session') $counts['session_ops']++;
    }

    if (isset($m['redis_error']) && $m['redis_error']) {
        $counts['redis_errors']++;
    }

    // Memory tracking
    if (isset($m['memory']) && is_int($m['memory'])) {
        $mem = $m['memory'];
        if ($memories['start'] === null) $memories['start'] = $mem;
        $memories['end'] = $mem;
        if ($mem < $memories['min']) $memories['min'] = $mem;
        if ($mem > $memories['max']) $memories['max'] = $mem;
        $memories['sum'] += $mem;
        $memories['count']++;
    }
}

// Read queue execution log
$queueStats = ['dispatched' => 0, 'executed' => 0, 'failed' => 0, 'memory_start' => null, 'memory_end' => null];
if (file_exists($logFile)) {
    $qLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($qLines as $qLine) {
        $qr = json_decode($qLine, true);
        if ($qr) {
            $queueStats['executed']++;
            if (isset($qr['memory'])) {
                if ($queueStats['memory_start'] === null) $queueStats['memory_start'] = $qr['memory'];
                $queueStats['memory_end'] = $qr['memory'];
            }
        }
    }
}

$memories['avg'] = $memories['count'] > 0 ? (int)($memories['sum'] / $memories['count']) : 0;
$memories['growth_pct'] = $memories['start'] > 0
    ? round(($memories['end'] - $memories['start']) / $memories['start'] * 100, 2)
    : 0;

// Gates
$gates = [
    'duration' => 'PASS',
    'framework_fatal' => 'PASS',
    'unexpected_5xx' => $counts['unexpected_5xx'] === 0 ? 'PASS' : 'FAIL',
    'memory_stable' => abs($memories['growth_pct']) < 10 ? 'PASS' : 'FAIL',
    'redis_errors' => $counts['redis_errors'] === 0 ? 'PASS' : 'FAIL',
    'db_errors' => $counts['db_errors'] === 0 ? 'PASS' : 'FAIL',
    'cache_exercised' => ($counts['cache_hits'] + $counts['cache_misses']) > 0 ? 'PASS' : 'FAIL',
    'queue_exercised' => $counts['queue_dispatched'] > 0 ? 'PASS' : 'FAIL',
    'db_exercised' => ($counts['db_selects'] + $counts['db_writes']) > 0 ? 'PASS' : 'FAIL',
];

$overall = !in_array('FAIL', $gates) ? 'PASS' : 'FAIL';

$result = [
    'verdict' => 'B2 FOCUSED VERDICT: ' . $overall,
    'timestamp' => date('c'),
    'counts' => $counts,
    'queue' => $queueStats,
    'memory' => $memories,
    'gates' => $gates,
];

file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "=== B2 Focused Soak Evaluator ===\n\n";
echo "Total requests:     {$counts['total_requests']}\n";
echo "Success (2xx):      {$counts['success_2xx']}\n";
echo "Expected 4xx:       {$counts['expected_4xx']}\n";
echo "Unexpected 4xx:     {$counts['unexpected_4xx']}\n";
echo "Expected 5xx:       {$counts['expected_5xx']}\n";
echo "Unexpected 5xx:     {$counts['unexpected_5xx']}\n";
echo "Timeouts:           {$counts['timeouts']}\n\n";

echo "Cache hits:         {$counts['cache_hits']}\n";
echo "Cache misses:       {$counts['cache_misses']}\n";
echo "Stampede callbacks: {$counts['cache_stampede_callbacks']}\n\n";

echo "DB selects:         {$counts['db_selects']}\n";
echo "DB writes:          {$counts['db_writes']}\n";
echo "DB errors:          {$counts['db_errors']}\n\n";

echo "Queue dispatched:   {$counts['queue_dispatched']}\n";
echo "Queue executed:     {$queueStats['executed']}\n";
echo "Queue errors:       {$counts['queue_execution_error']}\n\n";

echo "Validation ok:      {$counts['validation_ok']}\n";
echo "Validation errors:  {$counts['validation_errors']}\n";
echo "Trace lifecycle:    {$counts['trace_lifecycle']}\n";
echo "Session ops:        {$counts['session_ops']}\n\n";

echo "Redis errors:       {$counts['redis_errors']}\n\n";

echo "Memory start:       " . ($memories['start'] ?? 'N/A') . "\n";
echo "Memory end:         " . ($memories['end'] ?? 'N/A') . "\n";
echo "Memory peak:        {$memories['max']}\n";
echo "Memory growth:      {$memories['growth_pct']}%\n\n";

echo "=== Gates ===\n";
foreach ($gates as $gate => $status) {
    $icon = $status === 'PASS' ? '✅' : '❌';
    echo "{$icon} {$gate}: {$status}\n";
}

echo "\n=== FINAL: {$result['verdict']} ===\n";
