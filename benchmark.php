#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiroPHP Benchmark Suite
 *
 * Usage: php benchmark.php
 *        php benchmark.php --quick  (100 iters)
 *        php benchmark.php --json   (JSON output)
 */

namespace Siro\Benchmark;

use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\Container;

const WARMUP = 10;
const ITERS = 1000;

$autoloadPaths = [
    __DIR__ . '/siro-core/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
foreach ($autoloadPaths as $p) {
    if (file_exists($p)) { require_once $p; break; }
}

if (!class_exists(Request::class)) {
    fwrite(STDERR, "ERROR: Siro Core not found. Run from project root.\n");
    exit(1);
}

// === Benchmark Engine ===
class Result
{
    public function __construct(
        public readonly string $name,
        public readonly int $iters,
        /** @var array<float> */
        public array $samples = [],
    ) {}

    public function add(float $start, float $end): void { $this->samples[] = ($end - $start) * 1000; }

    /** @return array{avg:float, min:float, max:float, total:float, ops:float} */
    public function stats(): array
    {
        $n = count($this->samples);
        if ($n === 0) return ['avg' => 0, 'min' => 0, 'max' => 0, 'total' => 0, 'ops' => 0];
        $total = array_sum($this->samples);
        return [
            'avg' => $total / $n,
            'min' => min($this->samples),
            'max' => max($this->samples),
            'total' => $total,
            'ops' => ($total > 0) ? ($n / ($total / 1000)) : 0,
        ];
    }
}

/** @var array<int, Result> $results */
$results = [];

function bench(string $name, int $iters, callable $fn): Result
{
    $r = new Result($name, $iters);
    for ($i = 0; $i < WARMUP; $i++) { $fn(); }
    for ($i = 0; $i < $iters; $i++) { $s = microtime(true); $fn(); $r->add($s, microtime(true)); }
    return $r;
}

// === BENCHMARKS ===

$results[] = bench('Container::make(stdClass)', 10000, fn() => (new Container())->make(\stdClass::class));
$results[] = bench('Router: register static route', 10000, function () { $r = new Router(); $r->get('/test', fn() => 'ok'); });

$results[] = bench('Router: dispatch static O(1)', 10000, function () {
    /** @var Router|null $r */
    static $r = null; if ($r === null) { $r = new Router(); $r->get('/bench/test', fn(Request $req) => Response::success()); }
    $r->dispatch(new Request('GET', '/bench/test'));
});

$results[] = bench('Router: dispatch dynamic {id}', 10000, function () {
    /** @var Router|null $r */
    static $r = null; if ($r === null) { $r = new Router(); $r->get('/bench/user/{id}', fn(Request $req) => Response::success()); }
    $r->dispatch(new Request('GET', '/bench/user/12345'));
});

$results[] = bench('Response::success()', 10000, fn() => Response::success(['id' => 1, 'name' => 'Test']));
$results[] = bench('Middleware 5-layer pipeline', 5000, function () {
    /** @var Router|null $r */
    static $r = null;
    if ($r === null) {
        $r = new Router();
        $mws = [];
        for ($i = 0; $i < 5; $i++) { $mws[] = fn(Request $req, callable $n) => $n($req); }
        $r->get('/bench/mw', fn(Request $req) => Response::success(), $mws);
    }
    $r->dispatch(new Request('GET', '/bench/mw'));
});

$results[] = bench('Request::validate 5 rules', 5000, function () {
    $req = new Request('POST', '/t', [], [], ['email' => 'a@b.com', 'name' => 'John', 'age' => '25', 'score' => '100.5', 'active' => '1']);
    $req->validate(['email' => 'required|email', 'name' => 'required|min:2', 'age' => 'required|integer', 'score' => 'required|numeric', 'active' => 'required']);
});

$results[] = bench('Request::fromGlobals simulation', 5000, function () {
    new Request('POST', '/api/users', ['page' => '1', 'per_page' => '10'], ['content-type' => 'application/json', 'authorization' => 'Bearer test'], ['name' => 'Test'], '127.0.0.1');
});

// === System-level: PHP array + loop baseline ===
$results[] = bench('PHP baseline: empty loop', 10000, function () {
    $s = 0;
    for ($i = 0; $i < 10; $i++) {
        $s += $i;
    }
});

// === Full-stack throughput: App::boot + route + middleware + controller + response ===
// Pre-build router once for the full-stack benchmark
$fullStackRouter = new Router();
$fullStackRouter->get('/api/products', fn(Request $req) => Response::success([
    'id' => 1, 'name' => 'Test Product',
]));

$results[] = bench('Full-stack (route+middleware+response)', 500, function () use ($fullStackRouter): void {
    $req = new Request('GET', '/api/products', [], [
        'accept' => 'application/json',
    ]);
    $fullStackRouter->dispatch($req);
});

// === OUTPUT ===
$isJson = in_array('--json', $argv ?? []);
$totalAvg = 0; $totalOps = 0; $n = 0;

if ($isJson) {
    $out = ['benchmark' => 'SiroPHP', 'php' => PHP_VERSION, 'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2), 'results' => []];
    /** @var Result $r */
    foreach ($results as $r) { $s = $r->stats(); $out['results'][] = ['name' => $r->name, 'iters' => $r->iters, 'avg_ms' => round($s['avg'], 4), 'min_ms' => round($s['min'], 4), 'max_ms' => round($s['max'], 4), 'ops_per_sec' => round($s['ops'], 1)]; }
    echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
    exit;
}

echo "\n" . str_repeat('=', 100) . "\n";
echo "  SIRO FRAMEWORK BENCHMARK\n";
echo "  PHP " . PHP_VERSION . " | Memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
echo str_repeat('=', 100) . "\n\n";
printf("  %-42s %8s  %10s  %10s  %10s  %10s\n", "Benchmark", "Iters", "Avg (ms)", "Min (ms)", "Max (ms)", "Ops/sec");
echo str_repeat('-', 100) . "\n";
foreach ($results as $r) {
    $s = $r->stats();
    printf("  %-42s %8d  %10.4f  %10.4f  %10.4f  %10.1f\n", $r->name, $r->iters, $s['avg'], $s['min'], $s['max'], $s['ops']);
    $totalAvg += $s['avg']; $totalOps += $s['ops']; $n++;
}
echo str_repeat('-', 100) . "\n";
printf("  %-42s %8s  %10.4f  %10s  %10s  %10.1f\n", "AVERAGE", "", $totalAvg / max(1, $n), "", "", $totalOps / max(1, $n));
echo str_repeat('=', 100) . "\n\n";
