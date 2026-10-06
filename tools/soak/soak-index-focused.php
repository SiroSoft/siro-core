<?php
declare(strict_types=1);

/**
 * SiroPHP Focused Redis Integration Verification — Entry Point
 *
 * Fixed response contract for harness counter extraction.
 * Uses SoakTestJob for queue verification.
 * Exercises: Cache, DB, Queue, Validation, Trace, Session, Fail injection.
 */

$basePath = dirname(__DIR__);

if (!is_dir($basePath . '/storage')) {
    mkdir($basePath . '/storage', 0755, true);
}

require_once $basePath . '/vendor/autoload.php';

// Register soak test job for queue dispatch
require_once $basePath . '/scripts/soak/SoakTestJob.php';
\Siro\Core\Queue::registerJob(\Soak\SoakTestJob::class);

$app = new \Siro\Core\App($basePath);
$app->boot();

// ── GET routes ──

$app->router->get('/health', function () {
    return \Siro\Core\Response::json([
        'status' => 'ok',
        'timestamp' => microtime(true),
        'php_version' => PHP_VERSION,
        'pid' => getmypid(),
    ]);
});

$app->router->get('/metrics', function () {
    return \Siro\Core\Response::json([
        'success' => true,
        'message' => 'ok',
        'timestamp' => microtime(true),
        'pid' => getmypid(),
        'memory' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
    ]);
});

$app->router->get('/api/fast', function () {
    return \Siro\Core\Response::json([
        'ts' => microtime(true),
        'pid' => getmypid(),
    ]);
});

$app->router->get('/api/middleware', function (\Siro\Core\Request $request) {
    return \Siro\Core\Response::json([
        'method' => $request->method(),
        'path' => $request->path(),
        'trace_id' => \Siro\Core\Response::getRequestTraceId(),
    ]);
});

// ── Cache routes ──

$app->router->get('/api/cache/hit', function () {
    $start = microtime(true);
    \Siro\Core\Cache::remember('soak:cache_hit_key', 300, fn() => 'primed');
    $val = \Siro\Core\Cache::get('soak:cache_hit_key');
    return \Siro\Core\Response::json([
        'result' => 'cache_hit',
        'hit' => $val !== null,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

$app->router->get('/api/cache/miss', function () {
    $start = microtime(true);
    $key = 'soak:miss_' . (int)(microtime(true) * 10);
    $val = \Siro\Core\Cache::remember($key, 5, fn() => mt_rand(1, 999999));
    return \Siro\Core\Response::json([
        'result' => 'cache_miss',
        'key' => $key,
        'value' => $val,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

$app->router->get('/api/cache/stampede', function () {
    $start = microtime(true);
    $callCount = 0;
    $val = \Siro\Core\Cache::remember('soak:stampede_key', 10, function () use (&$callCount) {
        $callCount++;
        usleep(50000); // 50ms simulated work
        return ['computed_at' => microtime(true)];
    });
    return \Siro\Core\Response::json([
        'result' => 'stampede',
        'callback_executed' => $callCount,
        'value' => $val,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

// ── DB routes ──

$app->router->get('/api/db/select', function () use ($basePath) {
    $start = microtime(true);
    try {
        $dbPath = $basePath . '/storage/soak.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS soak_test (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, value INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT (datetime(\'now\')))');
        $count = $pdo->query('SELECT COUNT(*) as cnt FROM soak_test')->fetch()['cnt'] ?? 0;
        return \Siro\Core\Response::json([
            'result' => 'db_select',
            'rows' => (int)$count,
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
        ]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json(['result' => 'db_select_error', 'error' => $e->getMessage()], 500);
    }
});

$app->router->get('/api/trace/lifecycle', function () {
    return \Siro\Core\Response::json([
        'result' => 'trace',
        'trace_id' => \Siro\Core\Response::getRequestTraceId(),
        'lifecycle' => 'complete',
    ]);
});

$app->router->get('/api/session', function () {
    $start = microtime(true);
    $counter = $_SESSION['soak_counter'] ?? 0;
    $_SESSION['soak_counter'] = $counter + 1;
    return \Siro\Core\Response::json([
        'result' => 'session',
        'counter' => $counter + 1,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

// ── POST routes ──

$app->router->post('/api/validate', function (\Siro\Core\Request $request) {
    $body = $request->jsonAll();
    $errors = \Siro\Core\Validator::make((array)$body, [
        'name' => 'required|string|min:2',
        'email' => 'required|email',
        'value' => 'required|numeric|min:0',
    ]);
    if (!empty($errors)) {
        return \Siro\Core\Response::json(['result' => 'validation_error', 'valid' => false, 'errors' => $errors], 422);
    }
    return \Siro\Core\Response::json(['result' => 'validated', 'valid' => true]);
});

$app->router->post('/api/db/write', function (\Siro\Core\Request $request) use ($basePath) {
    $start = microtime(true);
    try {
        $dbPath = $basePath . '/storage/soak.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('INSERT INTO soak_test (name, value) VALUES (?, ?)');
        $stmt->execute(['soak_' . mt_rand(1, 10000), mt_rand(1, 1000)]);
        return \Siro\Core\Response::json([
            'result' => 'db_write',
            'inserted_id' => (int)$pdo->lastInsertId(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
        ]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json(['result' => 'db_write_error', 'error' => $e->getMessage()], 500);
    }
});

$app->router->post('/api/queue/dispatch', function () {
    try {
        \Siro\Core\Queue::push(\Soak\SoakTestJob::class, [
            'id' => 'soak_' . mt_rand(1, 999999),
            'dispatched_at' => time(),
            'to' => 'soak@test.local',
            'subject' => 'soak-test-' . mt_rand(1, 999999),
            'body' => 'Production soak queue test',
        ]);
        return \Siro\Core\Response::json(['result' => 'dispatched', 'dispatched' => true]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json(['result' => 'dispatch_error', 'dispatched' => false, 'error' => $e->getMessage()], 500);
    }
});

// ── Fail injection ──

$app->router->get('/api/fail/inject', function () {
    throw new \RuntimeException('Controlled soak failure injection');
});

$app->run();
