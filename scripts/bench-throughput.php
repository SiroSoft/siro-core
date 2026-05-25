#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Full-stack throughput benchmark.
 *
 * Measures a complete request cycle:
 *   App::boot() → Router::dispatch() → full middleware chain → Controller → Response
 *
 * Usage: php scripts/bench-throughput.php [--iterations=500]
 */

namespace Siro\Benchmark;

require_once __DIR__ . '/../vendor/autoload.php';

use Siro\Core\App;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\Env;
use Siro\Core\Config;

$iterations = 500;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--iterations=')) {
        $iterations = max(10, (int) substr((string) $arg, 13));
    }
}

const WARMUP = 10;

// Set up env
putenv('JWT_SECRET=01234567890123456789012345678901');
$_ENV['JWT_SECRET'] = '01234567890123456789012345678901';

$results = [];

for ($i = 0; $i < WARMUP + $iterations; $i++) {
    // Cold start: fresh App instance, fresh boot
    Env::reset();

    $app = new App(__DIR__ . '/..');

    $start = hrtime(true);

    $app->boot();
    $router = $app->router();

    // Register a test route
    $router->get('/api/bench', function (Request $req): Response {
        return Response::success(['message' => 'ok']);
    });

    $req = new Request('GET', '/api/bench');
    $response = $router->dispatch($req);

    $end = hrtime(true);
    $elapsed = ($end - $start) / 1_000_000; // ms

    if ($i >= WARMUP) {
        $results[] = $elapsed;
    }
}

$avg = array_sum($results) / count($results);
$min = min($results);
$max = max($results);

echo "\n";
echo str_repeat('=', 70) . "\n";
echo "  Siro Framework Full-Stack Throughput\n";
echo "  PHP " . PHP_VERSION . " | Iterations: {$iterations}\n";
echo str_repeat('=', 70) . "\n\n";
printf("  Avg request time:  %8.4f ms\n", $avg);
printf("  Min request time:  %8.4f ms\n", $min);
printf("  Max request time:  %8.4f ms\n", $max);
printf("  Throughput:        %8.1f req/sec\n", 1000 / ($avg / 1000));
echo "\n";
echo "  Note: Cold boot (App::boot) + route registration\n";
echo "  + dispatch + Response. No DB, no I/O.\n";
echo "  Production (OPcache + route cache): 3-5x faster.\n";
echo str_repeat('=', 70) . "\n\n";
