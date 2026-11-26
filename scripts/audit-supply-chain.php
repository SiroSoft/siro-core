#!/usr/bin/env php
<?php
declare(strict_types=1);

// 'NUL' is a Windows-only device; an unguarded `2>NUL` on Linux creates a
// literal file named NUL in the working directory (v1.4.2).
$nullDev = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
$output = shell_exec('composer audit --format=json ' . $nullDev);
if ($output === null) {
    fwrite(STDERR, "Failed to run composer audit\n");
    exit(2);
}

$result = json_decode($output, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo $output . "\n";
    fwrite(STDERR, "Failed to parse composer audit output\n");
    exit(2);
}

$advisories = $result['advisories'] ?? [];
$exitCode = 0;

foreach ($advisories as $packageName => $packageAdvisories) {
    foreach ($packageAdvisories as $advisory) {
        $severity = strtolower($advisory['severity'] ?? 'unknown');
        $title = $advisory['title'] ?? 'No title';
        $cve = $advisory['cve'] ?? 'N/A';
        $link = $advisory['link'] ?? '';

        $message = "[{$severity}] {$packageName}: {$title} (CVE: {$cve})";
        if ($link) {
            $message .= " - {$link}";
        }

        if ($severity === 'critical') {
            fwrite(STDERR, "FAIL: {$message}\n");
            $exitCode = 1;
        } elseif ($severity === 'high') {
            echo "WARN: {$message}\n";
            if ($exitCode === 0) {
                $exitCode = 0;
            }
        } else {
            echo "INFO: {$message}\n";
        }
    }
}

if ($exitCode === 0) {
    echo "Supply chain audit passed.\n";
}

exit($exitCode);
