<?php

declare(strict_types=1);

/**
 * SiroPHP Production Soak Harness — B1
 *
 * Self-contained soak test exercising real SiroPHP paths.
 *
 * Architecture:
 *   CLI harness (this process) → curl → PHP built-in server (separate process)
 *   Server-side memory sampled via /health/mem endpoint
 *   Samples streamed to disk (not accumulated in memory)
 *
 * Usage:
 *   php scripts/soak-harness.php --mode=short              # 5-minute validation
 *   php scripts/soak-harness.php --mode=full --hours=48     # 48-hour soak
 *   php scripts/soak-harness.php --mode=full --hours=1      # 1-hour soak
 *   php scripts/soak-harness.php --mode=short --duration=60 # 60-second quick test
 *
 * Options:
 *   --mode=short|full     Short validation or full soak
 *   --hours=N             Duration in hours (full mode only, default 48)
 *   --duration=N          Duration in seconds (overrides mode, min 30)
 *   --concurrency=N       Parallel workers (default 5)
 *   --output=DIR          Output directory (default: storage/soak)
 *   --port=N              Server port (default: 18080)
 */

// ============================================================
// CONFIGURATION
// ============================================================

$mode = 'short';
$hours = 48;
$concurrency = 5;
$outputDir = dirname(__DIR__) . '/storage/soak';
$port = 18080;
$baseUrl = "http://127.0.0.1:{$port}";

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--mode='))        $mode = substr($arg, 7);
    elseif (str_starts_with($arg, '--hours='))   $hours = max(1, (int) substr($arg, 8));
    elseif (str_starts_with($arg, '--concurrency=')) $concurrency = max(1, (int) substr($arg, 14));
    elseif (str_starts_with($arg, '--output='))  $outputDir = substr($arg, 9);
    elseif (str_starts_with($arg, '--port='))    $port = (int) substr($arg, 7);
    elseif (str_starts_with($arg, '--duration=')) { /* handled below */ }
}

$durationSeconds = ($mode === 'short') ? 300 : ($hours * 3600);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--duration=')) $durationSeconds = max(30, (int) substr($arg, 11));
}
if ($mode === 'full') $durationSeconds = $hours * 3600;

$sampleInterval = ($mode === 'short') ? 10 : 60;

// ============================================================
// SETUP — Create isolated test project
// ============================================================

$frameworkDir = dirname(__DIR__);
$soakDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_soak_' . bin2hex(random_bytes(4));
$projectDir = $soakDir . DIRECTORY_SEPARATOR . 'project';

echo "=== SiroPHP Soak Harness v2 ===\n";
echo "Mode: {$mode}\n";
echo "Duration: " . gmdate('H:i:s', $durationSeconds) . "\n";
echo "Concurrency: {$concurrency}\n";
echo "Sample interval: {$sampleInterval}s\n";
echo "Project: {$projectDir}\n\n";

// Create project structure
$dirs = [
    'app/Controllers', 'app/Models', 'app/Resources', 'config',
    'routes', 'storage/logs', 'storage/logs/traces', 'storage/cache',
    'storage/framework', 'database', 'public', 'tests',
];
foreach ($dirs as $dir) {
    @mkdir($projectDir . '/' . $dir, 0777, true);
}

// Write .env
file_put_contents($projectDir . '/.env', implode("\n", [
    'APP_NAME="SoakTest"',
    'APP_ENV=testing',
    'APP_DEBUG=true',
    'APP_KEY=soak_test_key_for_hmac_32_chars!',
    'JWT_SECRET=soak_jwt_secret_key_for_testing_32ch',
    'DB_CONNECTION=sqlite',
    'DB_DATABASE=' . $soakDir . '/soak.db',
    'APP_URL=http://127.0.0.1:' . $port,
    'CORS_ALLOWED_ORIGINS=*',
    'CACHE_DRIVER=file',
    'SESSION_DRIVER=file',
    'LOG_LEVEL=error',
]));

file_put_contents($projectDir . '/config/database.php', '<?php return ["driver" => "sqlite", "database" => "' . $soakDir . '/soak.db"];');
file_put_contents($projectDir . '/config/app.php', '<?php return ["name" => "SoakTest", "env" => "testing"];');
file_put_contents($projectDir . '/config/cache.php', '<?php return ["driver" => "file", "prefix" => "soak_"];');
file_put_contents($projectDir . '/config/session.php', '<?php return ["driver" => "file"];');

// Write routes — exercise all core framework paths
file_put_contents($projectDir . '/routes/api.php', <<<'PHP'
<?php
declare(strict_types=1);

use Siro\Core\Database;
use Siro\Core\Cache;
use Siro\Core\Session;
use Siro\Core\Logger;

$router = get_router();

// === HEALTH / SERVER MEMORY ===
$router->get('/health/live', function () {
    return ['status' => 'ok', 'time' => time()];
});

$router->get('/health/mem', function () {
    return [
        'memory_usage' => memory_get_usage(),
        'memory_peak' => memory_get_peak_usage(),
        'memory_real' => memory_get_usage(true),
        'gc_enabled' => gc_enabled(),
        'open_files' => function_exists('get_resources') ? count(get_resources('stream')) : -1,
    ];
});

// === DATABASE OPERATIONS ===
$router->get('/soak/db-read', function () {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS soak_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, value INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM soak_items');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ['count' => (int)($row['cnt'] ?? 0)];
});

$router->post('/soak/db-write', function () {
    $name = 'item_' . bin2hex(random_bytes(4));
    $value = random_int(1, 10000);
    Database::table('soak_items')->insert(['name' => $name, 'value' => $value]);
    return ['created' => $name, 'value' => $value];
});

$router->post('/soak/db-update', function () {
    $db = Database::connection();
    $db->exec('UPDATE soak_items SET value = value + 1 WHERE id = (SELECT MIN(id) FROM soak_items)');
    return ['updated' => true];
});

$router->post('/soak/db-transaction', function () {
    Database::beginTransaction();
    try {
        $name = 'txn_' . bin2hex(random_bytes(4));
        Database::table('soak_items')->insert(['name' => $name, 'value' => 999]);
        $db = Database::connection();
        $db->exec('UPDATE soak_items SET value = value + 1 WHERE name = "' . $name . '"');
        Database::commit();
        return ['committed' => true, 'name' => $name];
    } catch (\Throwable $e) {
        Database::rollBack();
        return ['rolled_back' => true, 'error' => $e->getMessage()];
    }
});

$router->post('/soak/db-rollback', function () {
    Database::beginTransaction();
    try {
        $name = 'rb_' . bin2hex(random_bytes(4));
        Database::table('soak_items')->insert(['name' => $name, 'value' => 0]);
        // Force rollback
        throw new \RuntimeException('Controlled rollback');
    } catch (\Throwable $e) {
        Database::rollBack();
        return ['rolled_back' => true];
    }
});

// === CACHE OPERATIONS ===
$router->get('/soak/cache-get', function () {
    $key = 'soak_key_' . random_int(1, 100);
    $val = Cache::get($key);
    return ['key' => $key, 'hit' => $val !== null, 'value' => $val];
});

$router->post('/soak/cache-set', function () {
    $key = 'soak_key_' . random_int(1, 100);
    $val = bin2hex(random_bytes(8));
    Cache::set($key, $val, 60);
    return ['key' => $key, 'set' => true];
});

$router->post('/soak/cache-remember', function () {
    $key = 'soak_remember_' . random_int(1, 50);
    $result = Cache::remember($key, 30, function () use ($key) {
        return 'computed_' . $key . '_' . time();
    });
    return ['key' => $key, 'value' => $result];
});

$router->post('/soak/cache-expire', function () {
    $key = 'soak_expire_' . random_int(1, 100);
    Cache::set($key, 'short_lived', 1); // expires in 1 second
    return ['key' => $key, 'ttl' => 1];
});

// === SESSION OPERATIONS ===
$router->get('/soak/session-read', function () {
    $session = Session::instance();
    $session->start();
    $count = ($session->get('soak_counter') ?? 0) + 1;
    $session->set('soak_counter', $count);
    return ['counter' => $count, 'session_id' => $session->getId()];
});

// === VALIDATION (failure injection) ===
$router->post('/soak/validate', function () {
    $request = \Siro\Core\Request::fromGlobals();
    $name = $request->input('name', '');
    if (strlen($name) < 3) {
        http_response_code(422);
        return ['error' => 'Validation failed', 'field' => 'name'];
    }
    return ['valid' => true, 'name' => $name];
});

// === EXCEPTION (failure injection) ===
$router->get('/soak/exception', function () {
    throw new \RuntimeException('Controlled soak exception');
});

// === TRACE LIFECYCLE ===
$router->get('/soak/trace-lifecycle', function () {
    Database::select('SELECT 1');
    Cache::set('trace_test_' . random_int(1, 1000), 'value', 10);
    return ['trace' => 'ok'];
});

// === LOGGER ===
$router->get('/soak/log-normal', function () {
    Logger::info('Soak: normal log entry', ['iteration' => random_int(1, 100000)]);
    return ['logged' => true];
});

$router->get('/soak/log-error', function () {
    Logger::error('Soak: controlled error log', ['type' => 'injected']);
    http_response_code(500);
    return ['error' => 'controlled'];
});

// === MIXED (realistic workload) ===
$router->post('/soak/mixed', function () {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS soak_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT, amount INTEGER, status TEXT DEFAULT "pending")');
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM soak_orders');
    $count = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt']);
    $product = 'product_' . random_int(1, 100);
    $amount = random_int(100, 9999);
    Database::table('soak_orders')->insert(['product' => $product, 'amount' => $amount]);
    Cache::set('order_count_' . $count, $count + 1, 60);
    return ['orders' => $count + 1, 'product' => $product, 'amount' => $amount];
});
PHP
);

// ============================================================
// HTTP HELPER
// ============================================================

function soakHttp(string $url, string $method = 'GET', ?string $body = null, int $timeout = 10): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $data = $response !== false ? json_decode($response, true) : null;
    return ['status' => $code, 'data' => $data, 'error' => $error, 'raw' => $response];
}

// ============================================================
// ACCEPTANCE CRITERIA
// ============================================================

$acceptance = [
    'duration_seconds' => $durationSeconds,
    'framework_fatal_errors' => 0,
    'unhandled_exceptions' => 0,
    'unexpected_worker_deaths' => 0,
    'db_connection_failures' => 0,
    'http_5xx' => 0,
    'total_requests_min' => 100, // at least 100 requests in short mode
    'memory_sustained_growth_pct_max' => 50, // max 50% growth after warmup
];

// ============================================================
// SOAK METRICS — Streams to disk, does not accumulate in memory
// ============================================================

class SoakMetrics
{
    private string $samplesFile;
    private string $outputDir;
    private int $requestCount = 0;
    private int $successCount = 0;
    private int $failureCount = 0;
    private int $http5xx = 0;
    private int $exceptions = 0;
    private int $dbFailures = 0;
    private int $cacheErrors = 0;
    private int $serverMemoryPeak = 0;
    private int $serverMemoryLast = 0;
    private int $serverMemoryMin = PHP_INT_MAX;
    private array $serverMemorySamples = [];
    private string $startTime;
    private int $startTimestamp;
    private $samplesHandle;

    public function __construct(string $outputDir)
    {
        $this->outputDir = $outputDir;
        @mkdir($outputDir, 0777, true);

        // Stream samples to file (not memory)
        $this->samplesFile = $outputDir . '/samples.jsonl';
        $this->samplesHandle = fopen($this->samplesFile, 'w');
        $this->startTime = date('Y-m-d\TH:i:sP');
        $this->startTimestamp = time();
    }

    /**
     * Record a sample with both harness and server memory.
     */
    public function recordSample(array $serverMem): void
    {
        $sample = [
            'time' => time(),
            'elapsed' => time() - $this->startTimestamp,
            'harness_memory' => memory_get_usage(),
            'server_memory' => $serverMem['memory_usage'] ?? 0,
            'server_peak' => $serverMem['memory_peak'] ?? 0,
            'requests' => $this->requestCount,
            'success' => $this->successCount,
            'failures' => $this->failureCount,
            'http_5xx' => $this->http5xx,
            'exceptions' => $this->exceptions,
        ];

        // Stream to disk
        fwrite($this->samplesHandle, json_encode($sample) . "\n");
        fflush($this->samplesHandle);

        // Track server memory for summary
        $mem = $serverMem['memory_usage'] ?? 0;
        if ($mem > 0) {
            $this->serverMemorySamples[] = $mem;
            $this->serverMemoryPeak = max($this->serverMemoryPeak, $serverMem['memory_peak'] ?? 0);
            $this->serverMemoryLast = $mem;
            $this->serverMemoryMin = min($this->serverMemoryMin, $mem);
        }
    }

    public function recordRequest(int $status, ?string $error = null): void
    {
        $this->requestCount++;
        if ($status >= 200 && $status < 400) {
            $this->successCount++;
        } else {
            $this->failureCount++;
        }
        if ($status >= 500) {
            $this->http5xx++;
        }
        if ($error !== null) {
            $this->exceptions++;
        }
    }

    public function recordDbFailure(): void  { $this->dbFailures++; }
    public function recordCacheError(): void  { $this->cacheErrors++; }

    public function getSummary(): array
    {
        $memSamples = $this->serverMemorySamples;
        $memFirst = $memSamples[0] ?? 0;
        $memLast = $this->serverMemoryLast;
        $memPeak = $this->serverMemoryPeak;
        $memMin = $this->serverMemoryMin;
        $n = count($memSamples);

        // Growth after warmup (first 10% excluded)
        $warmupIdx = max(1, (int)($n * 0.1));
        if ($n === 0) {
            // No server memory samples collected
            return [
                'start_time' => $this->startTime,
                'end_time' => date('Y-m-d\TH:i:sP'),
                'duration_seconds' => time() - $this->startTimestamp,
                'total_requests' => $this->requestCount,
                'success_count' => $this->successCount,
                'failure_count' => $this->failureCount,
                'http_5xx' => $this->http5xx,
                'exceptions' => $this->exceptions,
                'db_failures' => $this->dbFailures,
                'cache_errors' => $this->cacheErrors,
                'requests_per_second' => round($this->requestCount / max(1, time() - $this->startTimestamp), 2),
                'server_memory' => ['first' => 0, 'last' => 0, 'peak' => 0, 'min' => 0, 'growth_pct' => 0, 'post_warmup_growth_pct' => 0, 'stable_last_10pct' => true, 'last_10pct_range_bytes' => 0, 'samples' => 0],
                'harness_memory' => ['current' => memory_get_usage(), 'peak' => memory_get_peak_usage()],
                'sample_count' => 0,
            ];
        }

        $postWarmup = array_slice($memSamples, $warmupIdx);
        if (empty($postWarmup)) $postWarmup = $memSamples;
        $postWarmupFirst = $postWarmup[0] ?? $memFirst;
        $postWarmupLast = end($postWarmup) ?: $memLast;
        $postWarmupPeak = empty($postWarmup) ? 0 : max($postWarmup);
        $postWarmupGrowth = $postWarmupFirst > 0
            ? round(($postWarmupLast - $postWarmupFirst) / $postWarmupFirst * 100, 2)
            : 0;

        // Check if memory is still growing in last 10%
        $last10Pct = array_slice($memSamples, max(0, $n - max(1, (int)($n * 0.1))));
        $last10Min = empty($last10Pct) ? 0 : min($last10Pct);
        $last10Max = empty($last10Pct) ? 0 : max($last10Pct);
        $last10Range = max(0, $last10Max - $last10Min);
        $memoryStable = $n > 10 ? $last10Range < 102400 : true; // < 100KB range

        $elapsed = time() - $this->startTimestamp;

        return [
            'start_time' => $this->startTime,
            'end_time' => date('Y-m-d\TH:i:sP'),
            'duration_seconds' => $elapsed,
            'total_requests' => $this->requestCount,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'http_5xx' => $this->http5xx,
            'exceptions' => $this->exceptions,
            'db_failures' => $this->dbFailures,
            'cache_errors' => $this->cacheErrors,
            'requests_per_second' => $elapsed > 0 ? round($this->requestCount / $elapsed, 2) : 0,
            'server_memory' => [
                'first' => $memFirst,
                'last' => $memLast,
                'peak' => $memPeak,
                'min' => $memMin,
                'growth_pct' => $memFirst > 0 ? round(($memLast - $memFirst) / $memFirst * 100, 2) : 0,
                'post_warmup_growth_pct' => $postWarmupGrowth,
                'stable_last_10pct' => $memoryStable,
                'last_10pct_range_bytes' => $last10Range,
                'samples' => count($memSamples),
            ],
            'harness_memory' => [
                'current' => memory_get_usage(),
                'peak' => memory_get_peak_usage(),
            ],
            'sample_count' => $this->requestCount, // approximate
        ];
    }

    public function checkAcceptance(array $criteria): array
    {
        $failures = [];
        $summary = $this->getSummary();

        if ($summary['duration_seconds'] < $criteria['duration_seconds'] * 0.9) {
            $failures[] = "Duration {$summary['duration_seconds']}s < 90% of {$criteria['duration_seconds']}s";
        }
        if ($summary['total_requests'] < $criteria['total_requests_min']) {
            $failures[] = "Total requests {$summary['total_requests']} < minimum {$criteria['total_requests_min']}";
        }
        if ($summary['http_5xx'] > $criteria['http_5xx']) {
            $failures[] = "HTTP 5xx: {$summary['http_5xx']} > {$criteria['http_5xx']}";
        }
        if ($summary['exceptions'] > $criteria['unhandled_exceptions']) {
            $failures[] = "Exceptions: {$summary['exceptions']} > {$criteria['unhandled_exceptions']}";
        }
        if ($summary['db_failures'] > $criteria['db_connection_failures']) {
            $failures[] = "DB failures: {$summary['db_failures']} > {$criteria['db_connection_failures']}";
        }
        if ($summary['failure_count'] > 0 && $summary['failure_count'] > ($summary['total_requests'] * 0.01)) {
            $failures[] = "Failure rate " . round($summary['failure_count'] / $summary['total_requests'] * 100, 2) . "% > 1%";
        }
        // Memory check: post-warmup growth should be bounded
        if ($summary['server_memory']['post_warmup_growth_pct'] > $criteria['memory_sustained_growth_pct_max']) {
            $failures[] = "Server memory post-warmup growth {$summary['server_memory']['post_warmup_growth_pct']}% > {$criteria['memory_sustained_growth_pct_max']}%";
        }

        return $failures;
    }

    public function saveResults(): void
    {
        $summary = $this->getSummary();
        file_put_contents($this->outputDir . '/results.json', json_encode(['summary' => $summary], JSON_PRETTY_PRINT));
        fclose($this->samplesHandle);
    }

    public function hasFailures(): bool
    {
        return $this->failureCount > 0 || $this->http5xx > 0;
    }
}

// ============================================================
// WORKLOAD ROUTES — Weighted distribution
// ============================================================

$workloadRoutes = [
    // Database heavy (40%)
    ['GET',  '/soak/db-read', null, 40],
    ['POST', '/soak/db-write', null, 20],
    ['POST', '/soak/db-update', null, 10],
    ['POST', '/soak/db-transaction', null, 10],
    ['POST', '/soak/db-rollback', null, 5],
    // Cache (20%)
    ['GET',  '/soak/cache-get', null, 8],
    ['POST', '/soak/cache-set', null, 5],
    ['POST', '/soak/cache-remember', null, 4],
    ['POST', '/soak/cache-expire', null, 3],
    // Session (5%)
    ['GET',  '/soak/session-read', null, 5],
    // Mixed (10%)
    ['POST', '/soak/mixed', null, 10],
    // Validation (5%) — controlled failures
    ['POST', '/soak/validate', json_encode(['name' => 'ok']), 3],
    ['POST', '/soak/validate', json_encode(['name' => 'x']), 2], // triggers 422
    // Trace/Logger (5%)
    ['GET',  '/soak/trace-lifecycle', null, 3],
    ['GET',  '/soak/log-normal', null, 2],
    // Health (1%)
    ['GET',  '/health/live', null, 1],
    ['GET',  '/health/mem', null, 1],
];

// ============================================================
// WORKER — Run a batch of concurrent requests
// ============================================================

function runWorker(string $baseUrl, array $routes, int $count, SoakMetrics $metrics): void
{
    $mh = curl_multi_init();
    $handles = [];

    for ($i = 0; $i < $count; $i++) {
        $route = $routes[array_rand($routes)];
        $url = $baseUrl . $route[1];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $route[0],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        if ($route[2] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $route[2]);
        }
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['ch' => $ch, 'method' => $route[0], 'path' => $route[1]];
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1);
        }
    } while ($active > 0 && $status === CURLM_OK);

    foreach ($handles as $info) {
        $code = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
        $error = curl_error($info['ch']);
        $metrics->recordRequest($code, $error !== '' ? $error : null);
        curl_multi_remove_handle($mh, $info['ch']);
        curl_close($info['ch']);
    }

    curl_multi_close($mh);
}

// ============================================================
// MAIN
// ============================================================

echo "Setting up database...\n";
$dbDsn = 'sqlite:' . $soakDir . '/soak.db';
$db = new PDO($dbDsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE IF NOT EXISTS soak_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, value INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS soak_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT, amount INTEGER, status TEXT DEFAULT "pending")');
$db = null;

// Write router entry point
$routerFile = $projectDir . DIRECTORY_SEPARATOR . 'router.php';
file_put_contents($routerFile, '<?php' . "\n" .
    'declare(strict_types=1);' . "\n" .
    'require_once ' . var_export($frameworkDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', true) . ';' . "\n" .
    '$app = new \Siro\Core\App(' . var_export($projectDir, true) . ');' . "\n" .
    '$app->boot();' . "\n" .
    '$app->loadRoutes(' . var_export($projectDir . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php', true) . ');' . "\n" .
    '$app->run();' . "\n"
);

// Start built-in PHP server
echo "Starting PHP built-in server on port {$port}...\n";
$serverCmd = sprintf(
    'php -S %s -t %s %s 2>&1',
    escapeshellarg("127.0.0.1:{$port}"),
    escapeshellarg($projectDir),
    escapeshellarg($routerFile)
);
$serverProc = proc_open($serverCmd, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $serverPipes);

if (!is_resource($serverProc)) {
    fwrite(STDERR, "Failed to start PHP server\n");
    exit(1);
}

// Wait for server
echo "Waiting for server startup...\n";
sleep(2);

// Health check
$health = soakHttp($baseUrl . '/health/live');
if ($health['status'] !== 200) {
    fwrite(STDERR, "Server not healthy: status={$health['status']}, error={$health['error']}\n");
    stream_set_blocking($serverPipes[1], false);
    echo "Server output: " . stream_get_contents($serverPipes[1]) . "\n";
    proc_terminate($serverProc);
    exit(1);
}
echo "Server healthy ✓\n\n";

// Initialize metrics
$metrics = new SoakMetrics($outputDir);
$startTime = time();
$endTime = $startTime + $durationSeconds;
$iteration = 0;
$requestsPerIteration = $concurrency * 10;

// Checkpoint: stream acceptance criteria
file_put_contents($outputDir . '/acceptance.json', json_encode($acceptance, JSON_PRETTY_PRINT));

echo "Starting soak: " . date('Y-m-d H:i:s') . " → " . date('Y-m-d H:i:s', $endTime) . "\n\n";

while (time() < $endTime) {
    $iteration++;

    // Run batch
    runWorker($baseUrl, $workloadRoutes, $requestsPerIteration, $metrics);

    // Get server memory via dedicated endpoint
    $serverMem = soakHttp($baseUrl . '/health/mem', 'GET', null, 5);
    $serverMemData = $serverMem['data'] ?? [];

    // Record sample (streams to disk)
    $metrics->recordSample($serverMemData);

    // Progress update
    $elapsed = time() - $startTime;
    $progressInterval = max(1, 30);
    if ($iteration % max(1, (int)($progressInterval / max(1, $requestsPerIteration / $concurrency))) === 0) {
        $summary = $metrics->getSummary();
        printf(
            "  [%ds elapsed, %ds remaining] req=%d ok=%d fail=%d 5xx=%d server_mem=%s\n",
            $elapsed, max(0, $endTime - time()),
            $summary['total_requests'], $summary['success_count'],
            $summary['failure_count'], $summary['http_5xx'],
            number_format($summary['server_memory']['last'] ?? 0)
        );
    }
}

echo "\nSoak complete. Generating report...\n\n";

// Save results
$metrics->saveResults();
$summary = $metrics->getSummary();

// Generate report
$report = [];
$report[] = "═══════════════════════════════════════════════════════";
$report[] = "  SiroPHP Soak Report";
$report[] = "═══════════════════════════════════════════════════════";
$report[] = "";
$report[] = "Duration:        " . gmdate('H:i:s', $summary['duration_seconds']);
$report[] = "Mode:            {$mode}";
$report[] = "PHP:             " . PHP_VERSION;
$report[] = "OS:              " . PHP_OS_FAMILY;
$report[] = "Start:           {$summary['start_time']}";
$report[] = "End:             {$summary['end_time']}";
$report[] = "";
$report[] = "── Requests ──";
$report[] = "Total:           {$summary['total_requests']}";
$report[] = "Success:         {$summary['success_count']}";
$report[] = "Failures:        {$summary['failure_count']}";
$report[] = "HTTP 5xx:        {$summary['http_5xx']}";
$report[] = "Exceptions:      {$summary['exceptions']}";
$report[] = "Req/sec:         {$summary['requests_per_second']}";
$report[] = "";
$report[] = "── Server Memory ──";
$report[] = "First sample:    " . number_format($summary['server_memory']['first']) . " bytes";
$report[] = "Last sample:     " . number_format($summary['server_memory']['last']) . " bytes";
$report[] = "Peak:            " . number_format($summary['server_memory']['peak']) . " bytes";
$report[] = "Min:             " . number_format($summary['server_memory']['min']) . " bytes";
$report[] = "Total growth:    {$summary['server_memory']['growth_pct']}%";
$report[] = "Post-warmup:     {$summary['server_memory']['post_warmup_growth_pct']}%";
$report[] = "Stable (last 10%): " . ($summary['server_memory']['stable_last_10pct'] ? 'YES' : 'NO');
$report[] = "Last 10% range:  " . number_format($summary['server_memory']['last_10pct_range_bytes']) . " bytes";
$report[] = "Samples:         {$summary['server_memory']['samples']}";
$report[] = "";
$report[] = "── Harness Memory ──";
$report[] = "Current:         " . number_format($summary['harness_memory']['current']) . " bytes";
$report[] = "Peak:            " . number_format($summary['harness_memory']['peak']) . " bytes";
$report[] = "";
$report[] = "── Acceptance Criteria ──";

$acceptFailures = $metrics->checkAcceptance($acceptance);
if (empty($acceptFailures)) {
    $report[] = "✅ ALL GATES PASSED";
} else {
    $report[] = "❌ FAILURES:";
    foreach ($acceptFailures as $f) {
        $report[] = "  - {$f}";
    }
}
$report[] = "";
$report[] = "═══════════════════════════════════════════════════════";

$reportText = implode("\n", $report);
file_put_contents($outputDir . '/report.txt', $reportText);
echo $reportText . "\n\n";

// Cleanup
echo "Cleaning up...\n";
proc_terminate($serverProc);
proc_close($serverProc);

// Recursive cleanup helper
function soakRmDir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? soakRmDir($path) : @unlink($path);
    }
    @rmdir($dir);
}

@unlink($soakDir . '/soak.db');
@unlink($routerFile);
soakRmDir($projectDir);

echo "\nResults saved to: {$outputDir}/\n";
echo "  results.json  — machine-readable metrics\n";
echo "  report.txt    — human-readable summary\n";
echo "  samples.jsonl — per-sample data (streamed)\n";
echo "  acceptance.json — acceptance criteria\n";

exit(empty($acceptFailures) ? 0 : 1);
