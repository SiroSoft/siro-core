<?php

/**
 * Standalone script: run in background while PHPUnit executes.
 * Monitors PHPUnit's JUnit XML output file for progress.
 * If no new test completes in 60 seconds, writes a diagnostic.
 * 
 * Usage: php tools/diagnostics/CiHangWatcher.php <junit-xml-path> <timeout-seconds>
 */

$watchFile = $argv[1] ?? '/tmp/phpunit-results.xml';
$timeout = (int)($argv[2] ?? 60);
$logFile = '/tmp/ci-hang-watch.txt';

file_put_contents($logFile, "Watcher started at " . date('c') . "\n");
file_put_contents($logFile, "Watching: {$watchFile}\n", FILE_APPEND);
file_put_contents($logFile, "Timeout: {$timeout}s\n", FILE_APPEND);

$lastSize = 0;
$lastChange = time();
$checkInterval = 5;

while (true) {
    sleep($checkInterval);
    
    $currentSize = is_file($watchFile) ? filesize($watchFile) : 0;
    
    if ($currentSize > $lastSize) {
        $delta = $currentSize - $lastSize;
        $elapsed = time() - $lastChange;
        file_put_contents($logFile, "[" . date('H:i:s') . "] XML grew +{$delta} bytes (total {$currentSize}) after {$elapsed}s\n", FILE_APPEND);
        $lastSize = $currentSize;
        $lastChange = time();
    }
    
    $idle = time() - $lastChange;
    if ($idle >= $timeout) {
        file_put_contents($logFile, "[" . date('H:i:s') . "] STALL DETECTED — no XML changes for {$idle}s\n", FILE_APPEND);
        
        // Read last few lines of XML to find last completed test
        if (is_file($watchFile) && $currentSize > 0) {
            $content = file_get_contents($watchFile);
            // Find last <testcase element
            if (preg_match_all('/<testcase[^>]*name="([^"]*)"[^>]*class="([^"]*)"/', $content, $matches, PREG_SET_ORDER)) {
                $last = end($matches);
                file_put_contents($logFile, "Last completed test: {$last[2]}::{$last[1]}\n", FILE_APPEND);
                file_put_contents($logFile, "Total completed tests: " . count($matches) . "\n", FILE_APPEND);
            }
        }
        
        $lastChange = time(); // reset to avoid flooding
    }
    
    // Check if PHPUnit process is still running
    $psOutput = shell_exec('pgrep -f "phpunit.*phpunit-ci.xml" 2>/dev/null');
    if (empty(trim($psOutput ?? ''))) {
        file_put_contents($logFile, "[" . date('H:i:s') . "] PHPUnit process not found — exited\n", FILE_APPEND);
        break;
    }
    
    // Also check total elapsed
    $totalElapsed = time() - (int)($startTime ?? time());
    if ($totalElapsed > 1800) {
        file_put_contents($logFile, "[" . date('H:i:s') . "] Total time exceeded 30min — stopping watcher\n", FILE_APPEND);
        break;
    }
    
    if (!isset($startTime)) {
        $startTime = time();
    }
}
