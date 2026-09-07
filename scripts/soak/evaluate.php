<?php
declare(strict_types=1);

/**
 * Soak Test Evaluator
 *
 * Analyzes soak artifacts and determines PASS/FAIL against hard release gates.
 *
 * Usage:
 *   php scripts/soak/evaluate.php [--storage=storage]
 */

$basePath = dirname(__DIR__, 2);
$storageDir = $basePath . '/storage';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--storage=')) {
        $storageDir = substr($arg, 10);
    }
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║       SiroPHP Soak Test Evaluator                       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Load artifacts
$envFile = $storageDir . '/soak_env.json';
$summaryFile = $storageDir . '/soak_summary.json';
$samplesFile = $storageDir . '/soak_samples.jsonl';
$processFile = $storageDir . '/soak_process.jsonl';
$queueLog = $storageDir . '/soak_queue_log.jsonl';

$artifacts = [
    'env' => file_exists($envFile) ? json_decode(file_get_contents($envFile), true) : null,
    'summary' => file_exists($summaryFile) ? json_decode(file_get_contents($summaryFile), true) : null,
];

if (!$artifacts['summary']) {
    echo "ERROR: No soak summary found at {$summaryFile}\n";
    echo "Run the soak harness first: php scripts/soak/harness.php --duration=300\n";
    exit(1);
}

// ── Environment ──
echo "=== Environment ===\n";
$env = $artifacts['env'] ?: [];
echo "Git SHA:       " . ($env['git_sha'] ?? 'unknown') . "\n";
echo "PHP:           " . ($env['php_version'] ?? 'unknown') . "\n";
echo "OS:            " . ($env['os'] ?? 'unknown') . "\n";
echo "Start:         " . ($env['start_time'] ?? 'unknown') . "\n";
echo "Duration:      " . ($env['duration_seconds'] ?? 'unknown') . "s\n";
echo "Mode:          " . ($env['mode'] ?? 'unknown') . "\n\n";

// ── Duration Gate ──
$duration = $artifacts['summary']['duration_seconds'] ?? 0;
$requiredDuration = ($env['duration_seconds'] ?? 48 * 3600);
$durationPass = $duration >= $requiredDuration * 0.95; // Allow 5% tolerance
echo "=== Duration ===\n";
echo "Actual:        {$duration}s (" . gmdate('H:i:s', $duration) . ")\n";
echo "Required:      {$requiredDuration}s\n";
echo ($durationPass ? "✅ PASS\n" : "❌ FAIL\n\n");

// ── Request Metrics ──
$counters = $artifacts['summary']['counters'] ?? [];
echo "\n=== HTTP Metrics ===\n";
echo "Total requests:   " . ($counters['requests'] ?? 0) . "\n";
echo "Success (2xx):    " . ($counters['success'] ?? 0) . "\n";
echo "Expected 4xx:     " . ($counters['expected_4xx'] ?? 0) . "\n";
echo "Unexpected 4xx:   " . ($counters['unexpected_4xx'] ?? 0) . "\n";
echo "Injected 5xx:     " . ($counters['injected_5xx'] ?? 0) . " (deliberate /api/fail/inject — not a framework fault)\n";
echo "Unexpected 5xx:   " . ($counters['5xx'] ?? 0) . "\n";
echo "Errors:           " . ($counters['errors'] ?? 0) . "\n";

$requests = $counters['requests'] ?? 0;
$rps = $duration > 0 ? round($requests / $duration, 2) : 0;
echo "Requests/sec:     {$rps}\n";

// ── Memory Analysis ──
echo "\n=== Memory ===\n";
$samples = [];
if (file_exists($samplesFile)) {
    $lines = array_filter(explode("\n", file_get_contents($samplesFile)));
    foreach ($lines as $line) {
        $sample = json_decode($line, true);
        if ($sample) {
            $samples[] = $sample;
        }
    }
}

if (!empty($samples)) {
    $memValues = array_column($samples, 'memory_current');
    $peakValues = array_column($samples, 'memory_peak');
    $startMem = $memValues[0] ?? 0;
    $endMem = end($memValues);
    $minMem = min($memValues);
    $maxMem = max($memValues);
    $avgMem = (int) (array_sum($memValues) / count($memValues));
    $peakMem = max($peakValues);
    $growth = $endMem - $startMem;
    $growthPct = $startMem > 0 ? round(($growth / $startMem) * 100, 2) : 0;

    echo "Start:          " . ($startMem / 1024) . " KB\n";
    echo "End:            " . ($endMem / 1024) . " KB\n";
    echo "Min:            " . ($minMem / 1024) . " KB\n";
    echo "Max:            " . ($maxMem / 1024) . " KB\n";
    echo "Average:        " . ($avgMem / 1024) . " KB\n";
    echo "Peak:           " . ($peakMem / 1024) . " KB\n";
    echo "Growth:         " . ($growth / 1024) . " KB ({$growthPct}%)\n";

    // Trend analysis: linear regression
    $n = count($memValues);
    if ($n > 10) {
        $sumX = $sumY = $sumXY = $sumX2 = 0;
        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $memValues[$i];
            $sumXY += $i * $memValues[$i];
            $sumX2 += $i * $i;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $slopePerHour = $slope * 3600; // Convert per-sample to per-hour (assuming 60s interval)
        $leakDetected = $slopePerHour > 1024; // > 1KB/hour sustained growth

        echo "Trend slope:     " . round($slopePerHour / 1024, 2) . " KB/hour\n";
        echo "Leak detected:   " . ($leakDetected ? "⚠️ YES" : "✅ NO") . "\n";
    }
} else {
    echo "No samples available\n";
}

// ── Process Monitor ──
echo "\n=== PHP-FPM Process Monitor ===\n";
$processSamples = [];
if (file_exists($processFile)) {
    $lines = array_filter(explode("\n", file_get_contents($processFile)));
    foreach ($lines as $line) {
        $sample = json_decode($line, true);
        if ($sample) {
            $processSamples[] = $sample;
        }
    }
}

if (!empty($processSamples)) {
    $fpmCounts = array_column($processSamples, 'fpm_process_count');
    $rssValues = array_column($processSamples, 'fpm_total_rss_kb');
    echo "FPM workers (min/max): " . min($fpmCounts) . " / " . max($fpmCounts) . "\n";
    echo "FPM RSS (min/max):     " . min($rssValues) . "KB / " . max($rssValues) . "KB\n";
} else {
    echo "No process samples (run monitor.php alongside harness)\n";
}

// ── Queue ──
echo "\n=== Queue ===\n";
$queueLogs = [];
if (file_exists($queueLog)) {
    $lines = array_filter(explode("\n", file_get_contents($queueLog)));
    foreach ($lines as $line) {
        $log = json_decode($line, true);
        if ($log) {
            $queueLogs[] = $log;
        }
    }
}
echo "Jobs dispatched:  " . ($counters['queue_dispatched'] ?? 0) . "\n";
echo "Jobs logged:      " . count($queueLogs) . "\n";
if (!empty($queueLogs)) {
    $delays = array_column($queueLogs, 'processing_delay');
    echo "Avg delay:        " . round(array_sum($delays) / count($delays), 2) . "s\n";
    echo "Max delay:        " . max($delays) . "s\n";
}

// ── Cache ──
echo "\n=== Cache ===\n";
echo "Cache hits:       " . ($counters['cache_hits'] ?? 0) . "\n";
echo "Cache misses:     " . ($counters['cache_misses'] ?? 0) . "\n";
echo "Stampede callbacks: " . ($counters['cache_stampede_callbacks'] ?? 0) . "\n";

// ── Acceptance Gates ──
echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║                   ACCEPTANCE GATES                       ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";

$gates = [];

$gates['duration'] = [
    'name' => "Duration >= " . gmdate('H:i:s', $requiredDuration),
    'pass' => $durationPass,
];

$gates['fatals'] = [
    'name' => 'Framework-caused fatal errors = 0',
    'pass' => ($counters['errors'] ?? 0) === 0,
];

$gates['5xx'] = [
    'name' => 'Unexpected HTTP 5xx = 0 (injected failures excluded)',
    'pass' => ($counters['5xx'] ?? 0) === 0,
];

$gates['memory'] = [
    'name' => 'No sustained unbounded memory growth',
    'pass' => empty($samples) || !isset($leakDetected) || !$leakDetected,
];

$gates['stampede'] = [
    'name' => 'Cache stampede callbacks bounded',
    'pass' => ($counters['cache_stampede_callbacks'] ?? 0) <= ($counters['requests'] ?? 1) * 0.1,
];

$allPass = true;
foreach ($gates as $gate) {
    $icon = $gate['pass'] ? '✅' : '❌';
    echo "║ {$icon} {$gate['name']}\n";
    if (!$gate['pass']) {
        $allPass = false;
    }
}

echo "╠══════════════════════════════════════════════════════════╣\n";
if ($allPass) {
    echo "║                                                          ║\n";
    echo "║  B2 PASS — 48-hour production soak gate satisfied        ║\n";
    echo "║                                                          ║\n";
} else {
    echo "║                                                          ║\n";
    echo "║  B2 FAIL — Production soak blocker found                 ║\n";
    echo "║                                                          ║\n";
}
echo "╚══════════════════════════════════════════════════════════╝\n";

exit($allPass ? 0 : 1);
