<?php
declare(strict_types=1);

/**
 * SiroPHP 48h Production Soak — Entry Point
 *
 * Exercises: HTTP, DB (SQLite), Cache (file), Session, Trace
 * Accessed via: Nginx → PHP-FPM (port 8088)
 */

$basePath = dirname(__DIR__);

// Ensure storage directory exists
if (!is_dir($basePath . '/storage')) {
    mkdir($basePath . '/storage', 0755, true);
}

require_once $basePath . '/vendor/autoload.php';

$app = new \Siro\Core\App($basePath);
$app->boot();

// --- Health check ---
$app->router->get('/health', function () {
    return \Siro\Core\Response::json([
        'status' => 'ok',
        'timestamp' => microtime(true),
        'php_version' => PHP_VERSION,
        'pid' => getmypid(),
    ]);
});

// --- GET: cache hit/miss ---
$app->router->get('/cache', function () {
    $start = microtime(true);

    $counter = \Siro\Core\Cache::remember('soak:counter', 60, function () {
        static $c = 0;
        return ++$c;
    });

    $data = \Siro\Core\Cache::remember('soak:data', 120, function () {
        return ['items' => [1, 2, 3], 'ts' => microtime(true)];
    });

    return \Siro\Core\Response::json([
        'counter' => $counter,
        'data' => $data,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

// --- POST: validation ---
$app->router->post('/validate', function () {
    $body = \Siro\Core\Request::json();

    $result = \Siro\Core\Validator::make((array)$body, [
        'name' => 'required|string|min:2',
        'email' => 'required|email',
        'value' => 'required|numeric|min:0',
    ]);

    if ($result->fails()) {
        return \Siro\Core\Response::json([
            'valid' => false,
            'errors' => $result->errors(),
        ], 422);
    }

    return \Siro\Core\Response::json([
        'valid' => true,
        'data' => $body,
    ]);
});

// --- GET: DB operations ---
$app->router->get('/db', function () {
    $start = microtime(true);

    try {
        $dbPath = dirname(__DIR__) . '/storage/soak.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Ensure table
        $pdo->exec("CREATE TABLE IF NOT EXISTS soak_test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            value INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        )");

        // Insert
        $stmt = $pdo->prepare("INSERT INTO soak_test (name, value) VALUES (?, ?)");
        $stmt->execute(['soak_' . mt_rand(1, 10000), mt_rand(1, 1000)]);

        // Select count
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM soak_test")->fetch()['cnt'];

        // Update
        $lastId = $pdo->lastInsertId();
        $stmt2 = $pdo->prepare("UPDATE soak_test SET value = value + 1 WHERE id = ?");
        $stmt2->execute([$lastId]);

        // Transaction
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO soak_test (name, value) VALUES ('tx_test', 42)");
        $pdo->exec("UPDATE soak_test SET value = value - 1 WHERE name = 'tx_test'");
        $pdo->commit();

        return \Siro\Core\Response::json([
            'status' => 'ok',
            'rows' => (int)$count,
            'inserted_id' => (int)$lastId,
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
        ]);
    } catch (\Throwable $e) {
        return \Siro\Core\Response::json([
            'status' => 'error',
            'error' => $e->getMessage(),
        ], 500);
    }
});

// --- GET: session ---
$app->router->get('/session', function () {
    $start = microtime(true);
    $counter = $_SESSION['soak_counter'] ?? 0;
    $_SESSION['soak_counter'] = $counter + 1;

    return \Siro\Core\Response::json([
        'counter' => $counter + 1,
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

// --- GET: trace ---
$app->router->get('/trace', function () {
    return \Siro\Core\Response::json([
        'trace_id' => \Siro\Core\Response::getTraceId(),
        'status' => 'traced',
    ]);
});

// --- GET: combined stress ---
$app->router->get('/stress', function () {
    $start = microtime(true);

    // Cache
    $v = \Siro\Core\Cache::remember('soak:stress', 30, fn() => mt_rand(1, 999999));

    // DB
    $dbPath = dirname(__DIR__) . '/storage/soak.sqlite';
    $pdo = new \PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $count = $pdo->query("SELECT COUNT(*) as cnt FROM soak_test")->fetch()['cnt'] ?? 0;

    // Response
    return \Siro\Core\Response::json([
        'cache' => $v,
        'db_rows' => (int)$count,
        'trace_id' => \Siro\Core\Response::getTraceId(),
        'duration_ms' => round((microtime(true) - $start) * 1000, 3),
    ]);
});

$app->run();
