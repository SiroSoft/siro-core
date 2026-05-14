#!/usr/bin/env php
<?php
declare(strict_types=1);

$baselineFile = __DIR__ . '/../storage/benchmark/baseline.json';
$baselineDir = dirname($baselineFile);

if (!is_dir($baselineDir)) {
    mkdir($baselineDir, 0775, true);
}

$benchmarkScript = __DIR__ . '/../benchmark.php';
$command = PHP_BINARY . ' ' . escapeshellarg($benchmarkScript) . ' --json 2>&1';
$output = shell_exec($command);

if ($output === null || $output === '') {
    echo "ERROR: Could not run benchmark.php\n";
    exit(1);
}

$data = json_decode($output, true);
if (!is_array($data) || !isset($data['results'])) {
    echo "ERROR: Could not parse benchmark JSON output.\n";
    echo "Raw output:\n{$output}\n";
    exit(1);
}

$current = [];
foreach ($data['results'] as $result) {
    $current[$result['name']] = $result['avg_ms'];
}

$baseline = [];
if (file_exists($baselineFile)) {
    $content = file_get_contents($baselineFile);
    $decoded = json_decode(is_string($content) ? $content : '{}', true);
    $baseline = is_array($decoded) ? $decoded : [];
}

$isFirstRun = $baseline === [];
$failed = false;

echo str_repeat('-', 100) . "\n";
echo sprintf("%-45s %-15s %-15s %-10s %s\n", 'Benchmark', 'Baseline (ms)', 'Current (ms)', 'Delta %', 'Status');
echo str_repeat('-', 100) . "\n";

$newBaseline = [];
foreach ($current as $name => $value) {
    $baselineValue = $baseline[$name] ?? $value;
    $newBaseline[$name] = $value;

    if ($isFirstRun) {
        $delta = 0;
        $status = 'BASELINE';
    } else {
        $delta = (($value - $baselineValue) / $baselineValue) * 100;
        if ($delta > 10) {
            $status = 'FAIL';
            $failed = true;
        } elseif ($delta > 5) {
            $status = 'WARN';
        } elseif ($delta < -5) {
            $status = 'FASTER';
        } else {
            $status = 'PASS';
        }
    }

    echo sprintf("%-45s %-15s %-15s %-10.1f %s\n", $name,
        $isFirstRun ? '-' : number_format($baselineValue, 4),
        number_format($value, 4),
        $isFirstRun ? 0 : $delta,
        $status
    );
}

echo str_repeat('-', 100) . "\n";

if ($isFirstRun) {
    file_put_contents($baselineFile, json_encode($newBaseline, JSON_PRETTY_PRINT));
    echo "Baseline created: {$baselineFile}\n";
} elseif (!$failed) {
    $smoothed = [];
    foreach ($newBaseline as $name => $value) {
        $old = $baseline[$name] ?? $value;
        $smoothed[$name] = $old * 0.7 + $value * 0.3;
    }
    file_put_contents($baselineFile, json_encode($smoothed, JSON_PRETTY_PRINT));
    echo "Baseline updated (smoothed)\n";
}

if ($failed) {
    echo "PERFORMANCE REGRESSION DETECTED\n";
    exit(1);
}

if (!$isFirstRun) {
    echo "All benchmarks within tolerance\n";
}
exit(0);
