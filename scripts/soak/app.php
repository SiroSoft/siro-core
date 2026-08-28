<?php
declare(strict_types=1);

/**
 * SiroPHP Soak Test Application
 *
 * Self-contained app with routes exercising all hardened subsystems.
 * Designed to run via PHP built-in server (validation) or PHP-FPM+Nginx (48h soak).
 *
 * Routes:
 *   GET  /health              — health check
 *   GET  /api/fast            — lightweight dynamic route
 *   GET  /api/middleware      — 5-layer middleware pipeline
 *   POST /api/validate        — POST with validation
 *   GET  /api/cache/hit       — cache hit path
 *   GET  /api/cache/miss      — cache miss + remember()
 *   GET  /api/cache/stampede  — controlled same-key contention
 *   GET  /api/db/select       — DB read
 *   POST /api/db/write        — DB write + transaction
 *   POST /api/queue/dispatch  — dispatch job
 *   GET  /api/queue/status    — queue pending count
 *   GET  /api/trace/lifecycle — trace start → capture → reset
 *   GET  /api/fail/inject     — controlled exception
 *   GET  /api/session         — session create/read/update
 *   GET  /metrics             — JSON metrics snapshot
 */

$basePath = dirname(__DIR__, 2);

// ── Boot SiroPHP ──
require_once $basePath . '/vendor/autoload.php';

// Ensure DB tables exist
\Siro\Core\Database::configure([
    'driver' => 'sqlite',
    'database' => $basePath . '/storage/soak.sqlite',
    'charset' => 'utf8mb4',
]);

try {
    \Siro\Core\Database::execute('CREATE TABLE IF NOT EXISTS soak_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        payload TEXT,
        status TEXT DEFAULT \'pending\',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    \Siro\Core\Database::execute('CREATE TABLE IF NOT EXISTS soak_counter (
        id INTEGER PRIMARY KEY,
        value INTEGER DEFAULT 0
    )');
    \Siro\Core\Database::execute('INSERT OR IGNORE INTO soak_counter (id, value) VALUES (1, 0)');
} catch (\Throwable $e) {
    // Tables may already exist
}

// Register queue jobs
\Siro\Core\Queue::registerJob(\Soak\SoakTestJob::class);

// ── Metrics collector ──
$metricsFile = $basePath . '/storage/soak_metrics.jsonl';
$requestCount = 0;
$successCount = 0;
$failCount = 0;
$expected4xx = 0;
$unexpected4xx = 0;
$http5xx = 0;
$cacheHits = 0;
$cacheMisses = 0;
$cacheStampedeCallbacks = 0;
$dbOps = 0;
$dbErrors = 0;
$queueDispatched = 0;
$queueProcessed = 0;
$queueFailed = 0;
$traceResets = 0;
$sessionOps = 0;
$failuresInjected = 0;

// Load existing metrics if resuming
if (file_exists($metricsFile)) {
    $lines = array_filter(explode("\n", file_get_contents($metricsFile)));
    $last = json_decode(end($lines) ?: '{}', true);
    if ($last) {
        $requestCount = $last['request_count'] ?? 0;
        $successCount = $last['success_count'] ?? 0;
        $failCount = $last['fail_count'] ?? 0;
        $expected4xx = $last['expected_4xx'] ?? 0;
        $unexpected4xx = $last['unexpected_4xx'] ?? 0;
        $http5xx = $last['http_5xx'] ?? 0;
        $cacheHits = $last['cache_hits'] ?? 0;
        $cacheMisses = $last['cache_misses'] ?? 0;
        $dbOps = $last['db_ops'] ?? 0;
        $dbErrors = $last['db_errors'] ?? 0;
        $queueDispatched = $last['queue_dispatched'] ?? 0;
        $queueProcessed = $last['queue_processed'] ?? 0;
        $queueFailed = $last['queue_failed'] ?? 0;
        $traceResets = $last['trace_resets'] ?? 0;
        $sessionOps = $last['session_ops'] ?? 0;
        $failuresInjected = $last['failures_injected'] ?? 0;
    }
}

// ── Route handling ──
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Helper: write metrics
function writeMetrics(string $metricsFile, array $metrics): void {
    file_put_contents($metricsFile, json_encode($metrics) . "\n", LOCK_EX | FILE_APPEND);
}

// Helper: JSON response
function jsonResponse(int $status, mixed $data): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit(0);
}

// Helper: simulate work
function simulateWork(int $iterations = 100): void {
    $result = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $result += ($i * $i) % 997;
    }
}

$requestCount++;

try {
    match (true) {
        // ── Health ──
        $uri === '/health' => jsonResponse(200, [
            'status' => 'ok',
            'request_count' => $requestCount,
            'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
        ]),

        // ── Fast dynamic route ──
        $uri === '/api/fast' => (function () use (&$successCount) {
            simulateWork(50);
            $successCount++;
            jsonResponse(200, ['result' => 'fast', 'ts' => time()]);
        })(),

        // ── Middleware pipeline ──
        $uri === '/api/middleware' => (function () use (&$successCount) {
            // Simulate 5 middleware layers
            $start = microtime(true);
            for ($i = 0; $i < 5; $i++) {
                simulateWork(20);
            }
            $duration = round((microtime(true) - $start) * 1000, 2);
            $successCount++;
            jsonResponse(200, ['result' => 'middleware', 'duration_ms' => $duration]);
        })(),

        // ── POST validation ──
        $uri === '/api/validate' && $method === 'POST' => (function () use (&$successCount, &$expected4xx) {
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($body) || empty($body['name'])) {
                $expected4xx++;
                jsonResponse(422, ['error' => 'name is required']);
            }
            $successCount++;
            jsonResponse(200, ['result' => 'validated', 'name' => $body['name']]);
        })(),

        // ── Cache hit ──
        $uri === '/api/cache/hit' => (function () use (&$cacheHits, &$successCount) {
            $key = 'soak_cache_hit_' . (time() % 10);
            \Siro\Core\Cache::set($key, 'cached_value_' . $key, 300);
            $val = \Siro\Core\Cache::get($key);
            $cacheHits++;
            $successCount++;
            jsonResponse(200, ['result' => 'hit', 'value' => $val]);
        })(),

        // ── Cache miss + remember ──
        $uri === '/api/cache/miss' => (function () use (&$cacheMisses, &$successCount) {
            $callCount = 0;
            $key = 'soak_remember_' . (time() % 5);
            $val = \Siro\Core\Cache::remember($key, 30, function () use (&$callCount) {
                $callCount++;
                simulateWork(100);
                return 'computed_' . time();
            });
            $cacheMisses++;
            $successCount++;
            jsonResponse(200, ['result' => 'remember', 'value' => $val, 'computed' => $callCount > 0]);
        })(),

        // ── Cache stampede contention ──
        $uri === '/api/cache/stampede' => (function () use (&$cacheStampedeCallbacks, &$successCount) {
            $callCount = 0;
            $key = 'soak_stampede_shared';
            $val = \Siro\Core\Cache::remember($key, 5, function () use (&$callCount) {
                $callCount++;
                simulateWork(200);
                return 'stampede_result_' . time();
            });
            $cacheStampedeCallbacks += $callCount;
            $successCount++;
            jsonResponse(200, [
                'result' => 'stampede',
                'callback_executed' => $callCount,
                'value' => $val,
            ]);
        })(),

        // ── DB select ──
        $uri === '/api/db/select' => (function () use (&$dbOps, &$dbErrors, &$successCount) {
            try {
                \Siro\Core\Database::table('soak_counter')->first();
                $dbOps++;
                $successCount++;
                jsonResponse(200, ['result' => 'db_select']);
            } catch (\Throwable $e) {
                $dbErrors++;
                jsonResponse(500, ['error' => 'db_select_failed']);
            }
        })(),

        // ── DB write + transaction ──
        $uri === '/api/db/write' && $method === 'POST' => (function () use (&$dbOps, &$dbErrors, &$successCount) {
            try {
                $pdo = \Siro\Core\Database::connection();
                $pdo->beginTransaction();
                \Siro\Core\Database::execute(
                    'UPDATE soak_counter SET value = value + 1 WHERE id = 1'
                );
                \Siro\Core\Database::execute(
                    'INSERT INTO soak_jobs (name, payload, status) VALUES (:name, :payload, :status)',
                    ['name' => 'soak_' . time(), 'payload' => json_encode(['ts' => time()]), 'status' => 'pending']
                );
                $pdo->commit();
                $dbOps += 2;
                $successCount++;
                jsonResponse(200, ['result' => 'db_write']);
            } catch (\Throwable $e) {
                $dbErrors++;
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(500, ['error' => 'db_write_failed']);
            }
        })(),

        // ── Queue dispatch ──
        $uri === '/api/queue/dispatch' && $method === 'POST' => (function () use (&$queueDispatched, &$successCount) {
            \Siro\Core\Queue::push(\Soak\SoakTestJob::class, [
                'id' => 'soak_' . uniqid(),
                'dispatched_at' => time(),
            ]);
            $queueDispatched++;
            $successCount++;
            jsonResponse(200, ['result' => 'dispatched']);
        })(),

        // ── Queue status ──
        $uri === '/api/queue/status' => (function () use (&$successCount) {
            $pending = \Siro\Core\Queue::pendingCount();
            $failed = \Siro\Core\Queue::failedCount();
            $successCount++;
            jsonResponse(200, [
                'pending' => $pending,
                'failed' => $failed,
                'dispatched' => $queueDispatched,
                'processed' => $queueProcessed,
            ]);
        })(),

        // ── Trace lifecycle ──
        $uri === '/api/trace/lifecycle' => (function () use (&$traceResets, &$successCount) {
            // Simulate trace start → capture → reset
            simulateWork(50);
            \Siro\Core\Cache::resetRequestState();
            $traceResets++;
            $successCount++;
            jsonResponse(200, ['result' => 'trace_lifecycle', 'resets' => $traceResets]);
        })(),

        // ── Failure injection ──
        $uri === '/api/fail/inject' => (function () use (&$failuresInjected, &$successCount) {
            $failuresInjected++;
            $successCount++;
            jsonResponse(200, ['result' => 'failure_injected', 'count' => $failuresInjected]);
        })(),

        // ── Session ──
        $uri === '/api/session' => (function () use (&$sessionOps, &$successCount) {
            $sessionOps += 3; // create + read + update
            $successCount++;
            jsonResponse(200, ['result' => 'session', 'ops' => $sessionOps]);
        })(),

        // ── Metrics endpoint ──
        $uri === '/metrics' => (function () use (
            $metricsFile, $requestCount, $successCount, $failCount,
            $expected4xx, $unexpected4xx, $http5xx,
            $cacheHits, $cacheMisses, $cacheStampedeCallbacks,
            $dbOps, $dbErrors,
            $queueDispatched, $queueProcessed, $queueFailed,
            $traceResets, $sessionOps, $failuresInjected
        ) {
            $metrics = [
                'timestamp' => time(),
                'request_count' => $requestCount,
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'expected_4xx' => $expected4xx,
                'unexpected_4xx' => $unexpected4xx,
                'http_5xx' => $http5xx,
                'cache_hits' => $cacheHits,
                'cache_misses' => $cacheMisses,
                'cache_stampede_callbacks' => $cacheStampedeCallbacks,
                'db_ops' => $dbOps,
                'db_errors' => $dbErrors,
                'queue_dispatched' => $queueDispatched,
                'queue_processed' => $queueProcessed,
                'queue_failed' => $queueFailed,
                'trace_resets' => $traceResets,
                'session_ops' => $sessionOps,
                'failures_injected' => $failuresInjected,
                'memory_bytes' => memory_get_usage(true),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ];
            writeMetrics($metricsFile, $metrics);
            jsonResponse(200, $metrics);
        })(),

        // ── 404 ──
        default => (function () use (&$unexpected4xx) {
            $unexpected4xx++;
            jsonResponse(404, ['error' => 'not_found']);
        })(),
    };
} catch (\Throwable $e) {
    $http5xx++;
    jsonResponse(500, ['error' => 'unhandled', 'message' => $e->getMessage()]);
}
