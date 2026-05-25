#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Boot time benchmark for Siro Framework.
 *
 * Measures cold boot time: App::boot() including env loading,
 * config loading, container initialization, and service registration.
 *
 * Usage: php scripts/bench-boot.php
 *        php scripts/bench-boot.php --iterations=100
 */

namespace Siro\Benchmark;

require_once __DIR__ . '/../vendor/autoload.php';

const WARMUP = 5;

$iterations = 50;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--iterations=')) {
        $iterations = max(1, (int) substr((string) $arg, 13));
    }
}

// Set JWT_SECRET so App::boot() passes security validation
putenv('JWT_SECRET=01234567890123456789012345678901');
$_ENV['JWT_SECRET'] = '01234567890123456789012345678901';

$results = [];

for ($i = 0; $i < WARMUP + $iterations; $i++) {
    // Reset env state between boots
    \Siro\Core\Env::reset();

    // Remove cached config so we measure cold boot each time
    $configCache = __DIR__ . '/../storage/framework/config.php';
    if (file_exists($configCache)) {
        unlink($configCache);
    }

    $start = hrtime(true);

    $app = new \Siro\Core\App(__DIR__ . '/..');
    $app->boot();

    $end = hrtime(true);
    $elapsed = ($end - $start) / 1_000_000; // ns → ms

    if ($i >= WARMUP) {
        $results[] = $elapsed;
    }
}

$avg = array_sum($results) / count($results);
$min = min($results);
$max = max($results);

echo "\n";
echo str_repeat('=', 70) . "\n";
echo "  Siro Framework Boot Time Benchmark\n";
echo "  PHP " . PHP_VERSION . " | Iterations: {$iterations}\n";
echo str_repeat('=', 70) . "\n\n";
printf("  Avg boot time:  %8.4f ms\n", $avg);
printf("  Min boot time:  %8.4f ms\n", $min);
printf("  Max boot time:  %8.4f ms\n", $max);
echo "\n";
echo "  Note: This includes env loading, config loading,\n";
echo "  service container init, security validation.\n";
echo "  With OPcache + route caching, expect 2-5x faster.\n";
echo str_repeat('=', 70) . "\n\n";
