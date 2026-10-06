<?php
declare(strict_types=1);

/**
 * SiroPHP 48h Production Soak — Entry Point v3
 *
 * 14 routes matching harness.php + /metrics for periodic sampling
 * Accessed via: Nginx → PHP-FPM (port 8088)
 */

$basePath = dirname(__DIR__);

if (!is_dir($basePath . '/storage')) {
    mkdir($basePath . '/storage', 0755, true);
}

require_once $basePath . '/vendor/autoload.php';

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

$app->router->get('/api/cache/hit', function () {
    $start = microtime(true);
    \Siro\Core\Cache::remember('soak:cache_hit_key', 300, fn() => 'primed');
    $val = \Siro\Core\Cache::get('soak:cache_hit_key');
    return \Siro\Core\Response::json([
        'hit' => $val !== null,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

$app->router->get('/api/cache/miss', function () {
    $start = microtime(true);
    $key = 'soak:miss_' . (int)(microtime(true) * 10);
    $val = \Siro\Core\Cache::remember($key, 5, fn() => mt_rand(1, 999999));
    return \Siro\Core\Response::json([
        'key' => $key,
        'value' => $val,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

$app->router->get('/api/cache/stampede', function () {
    $start = microtime(true);
    $val = \Siro\Core\Cache::remember('soak:stampede_key', 10, function () {
        usleep(50000);
        return ['computed_at' => microtime(true)];
    });
    return \Siro\Core\Response::json([
        'value' => $val,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

$app->router->get('/api/db/select', function () {
    $start = microtime(true);
    try {
        $dbPath = dirname(__DIR__) . '/storage/soak.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS soak_test (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, value INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT (datetime(\'now\')))');
        $count = $pdo->query('SELECT COUNT(*) as cnt FROM soak_test')->fetch()['cnt'] ?? 0;
        return \Siro\Core\Response::json([
            'rows' => (int)$count,
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
        ]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json(['error' => $e->getMessage()], 500);
    }
});

$app->router->get('/api/trace/lifecycle', function () {
    return \Siro\Core\Response::json([
        'trace_id' => \Siro\Core\Response::getRequestTraceId(),
        'lifecycle' => 'complete',
    ]);
});

$app->router->get('/api/session', function () {
    $start = microtime(true);
    $counter = $_SESSION['soak_counter'] ?? 0;
    $_SESSION['soak_counter'] = $counter + 1;
    return \Siro\Core\Response::json([
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
        return \Siro\Core\Response::json(['valid' => false, 'errors' => $errors], 422);
    }
    return \Siro\Core\Response::json(['valid' => true]);
});

$app->router->post('/api/db/write', function (\Siro\Core\Request $request) {
    $start = microtime(true);
    try {
        $dbPath = dirname(__DIR__) . '/storage/soak.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('INSERT INTO soak_test (name, value) VALUES (?, ?)');
        $stmt->execute(['soak_' . mt_rand(1, 10000), mt_rand(1, 1000)]);
        return \Siro\Core\Response::json([
            'inserted_id' => (int)$pdo->lastInsertId(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
        ]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json(['error' => $e->getMessage()], 500);
    }
});

$app->router->post('/api/queue/dispatch', function () {
    try {
        \Siro\Core\Queue::push(\Siro\Core\Queue\Jobs\SendMailJob::class, [
            'to' => 'soak@test.local',
            'subject' => 'soak-test-' . mt_rand(1, 999999),
            'body' => 'Production soak queue test',
        ]);
        return \Siro\Core\Response::json(['dispatched' => true]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json(['dispatched' => false, 'error' => $e->getMessage()], 500);
    }
});

// ── Fail injection ──

$app->router->get('/api/fail/inject', function () {
    throw new \RuntimeException('Controlled soak failure injection');
});

$app->run();
