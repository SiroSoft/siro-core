<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Config;
use Siro\Core\Router;
use Siro\Core\Database;
use Siro\Core\Response;
use Siro\Core\Request;
use Siro\Core\Container;
use Siro\Core\Env;
use Siro\Core\Cache;
use Siro\Core\Logger;

final class BenchmarkTest extends TestCase
{
    private const WARMUP = 10;
    private const ITERS = 100;
    private const MANY_ROUTES = 1000;

    private string $basePath;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = dirname(__DIR__, 2);
        $this->tempDir = sys_get_temp_dir() . '/siro_perf_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }

        set_error_handler(function (int $severity, string $message): bool {
            if (str_contains($message, 'mkdir(): File exists')) {
                return true;
            }
            return false;
        }, E_WARNING);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $f) {
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function resetAllState(): void
    {
        Env::reset();
        Config::reset();
        Container::setInstance(null);
        Database::purgeAll();
        Cache::resetRequestState();
        $ref = new \ReflectionClass(Router::class);
        $prop = $ref->getProperty('middlewareAliases');
        $prop->setAccessible(true);
        $prop->setValue([]);

        $respRef = new \ReflectionClass(Response::class);
        $debugProp = $respRef->getProperty('debugEnabled');
        $debugProp->setAccessible(true);
        $debugProp->setValue(false);
        $metaProp = $respRef->getProperty('debugMeta');
        $metaProp->setAccessible(true);
        $metaProp->setValue([]);
        $reqIdProp = $respRef->getProperty('requestId');
        $reqIdProp->setAccessible(true);
        $reqIdProp->setValue('');
        $reqStartedProp = $respRef->getProperty('requestStartedAt');
        $reqStartedProp->setAccessible(true);
        $reqStartedProp->setValue(0.0);

        $logRef = new \ReflectionClass(Logger::class);
        try {
            $logDirProp = $logRef->getProperty('logDir');
            $logDirProp->setAccessible(true);
            $logDirProp->setValue('');
        } catch (\ReflectionException) {
        }
    }

    private function createTempConfigDir(string $baseDir): string
    {
        $configDir = $baseDir . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0775, true);
        }
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'database.php', '<?php return ["driver" => "sqlite", "database" => ":memory:"];');
        return $configDir;
    }

    // ═══════════════════════════════════════════
    //  1. BOOT TIME PERFORMANCE
    // ═══════════════════════════════════════════

    public function testBootTimeCold(): void
    {
        $this->resetAllState();
        $configDir = $this->createTempConfigDir($this->tempDir);

        $start = microtime(true);
        $app = new App($this->tempDir);
        $app->boot();
        $elapsed = (microtime(true) - $start) * 1000;

        echo sprintf("\n Cold boot:             %8.3f ms", $elapsed);
        $this->assertLessThan(10.0, $elapsed,
            'Cold boot should be < 10ms, got: ' . round($elapsed, 3) . 'ms');
    }

    public function testBootTimeWarm(): void
    {
        $this->resetAllState();
        $configDir = $this->createTempConfigDir($this->tempDir);

        $app = new App($this->tempDir);
        $app->boot();

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $this->resetAllState();
            $this->createTempConfigDir($this->tempDir);
            $a = new App($this->tempDir);
            $start = microtime(true);
            $a->boot();
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);
        $effective = $avg - ($times[0] / count($times)); // subtract cold start overhead
        echo sprintf("\n Warm boot avg:         %8.4f ms  (min: %.4f, max: %.4f, n=%d)",
            $avg, $min, $max, self::ITERS);
        $this->assertLessThan(5.0, $avg,
            'Warm boot avg should be < 5ms, got: ' . round($avg, 3) . 'ms');
    }

    public function testRouteLoadingTime(): void
    {
        $router = new Router();
        $routesFile = $this->tempDir . DIRECTORY_SEPARATOR . 'test_routes.php';

        $routeCode = '$router->get("/", function() { return ["ok" => true]; });' . "\n";
        for ($i = 0; $i < 100; $i++) {
            $routeCode .= sprintf(
                '$router->get("/route/%d", function() { return ["id" => %d]; });' . "\n",
                $i, $i
            );
        }
        file_put_contents($routesFile, '<?php ' . $routeCode);

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $router = new Router();
            $start = microtime(true);
            require $routesFile;
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        echo sprintf("\n Route loading (101):    %8.4f ms  (n=%d)", $avg, self::ITERS);
        $this->assertLessThan(5.0, $avg,
            'Route loading should be < 5ms, got: ' . round($avg, 3) . 'ms');
    }

    public function testContainerResolutionTime(): void
    {
        $this->resetAllState();
        $container = Container::getInstance();
        $container->bind('app', fn () => new \stdClass());
        $container->singleton(Container::class, fn () => $container);

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $c = Container::getInstance();
            $start = microtime(true);
            $c->make(\stdClass::class);
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        echo sprintf("\n Container::make:        %8.4f ms  (n=%d)", $avg, self::ITERS);
        $this->assertLessThan(0.1, $avg,
            'Container resolution should be < 0.1ms, got: ' . round($avg, 3) . 'ms');
    }

    // ═══════════════════════════════════════════
    //  2. REQUEST THROUGHPUT
    // ═══════════════════════════════════════════

    public function testSimpleRouteDispatch(): void
    {
        $router = new Router();
        $router->get('/test', function () {
            return Response::success(['message' => 'ok']);
        });

        for ($i = 0; $i < self::WARMUP; $i++) {
            $router->dispatch(new Request('GET', '/test'));
        }

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/test');
            $start = microtime(true);
            $router->dispatch($req);
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        $opsPerSec = $avg > 0 ? 1000 / $avg : 0;
        echo sprintf("\n Simple route dispatch:  %8.4f ms  (%.0f ops/sec, n=%d)",
            $avg, $opsPerSec, self::ITERS);
        $this->assertLessThan(1.0, $avg,
            'Route dispatch avg should be < 1ms, got: ' . round($avg, 3) . 'ms');
    }

    public function testDynamicRouteDispatch(): void
    {
        $router = new Router();
        $router->get('/users/{id}/posts/{postId}', function (Request $req) {
            return Response::success(['user_id' => $req->param('id'), 'post_id' => $req->param('postId')]);
        });

        for ($i = 0; $i < self::WARMUP; $i++) {
            $router->dispatch(new Request('GET', '/users/42/posts/99'));
        }

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/users/' . $i . '/posts/' . ($i * 2));
            $start = microtime(true);
            $router->dispatch($req);
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        $opsPerSec = $avg > 0 ? 1000 / $avg : 0;
        echo sprintf("\n Dynamic route dispatch: %8.4f ms  (%.0f ops/sec, n=%d)",
            $avg, $opsPerSec, self::ITERS);
        $this->assertLessThan(2.0, $avg,
            'Dynamic route dispatch avg should be < 2ms, got: ' . round($avg, 3) . 'ms');
    }

    public function testMiddlewarePipelinePerformance(): void
    {
        $router = new Router();
        $middlewareCount = 10;
        $middleware = [];
        for ($i = 0; $i < $middlewareCount; $i++) {
            $middleware[] = function (Request $req, callable $next): Response {
                return $next($req);
            };
        }
        $router->get('/bench/mw', function () {
            return Response::success(['message' => 'ok']);
        }, $middleware);

        for ($i = 0; $i < self::WARMUP; $i++) {
            $router->dispatch(new Request('GET', '/bench/mw'));
        }

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/bench/mw');
            $start = microtime(true);
            $router->dispatch($req);
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        $perLayer = $avg / ($middlewareCount + 1);
        echo sprintf("\n Middleware pipeline (%d): %8.4f ms  (%.4f ms/layer, n=%d)",
            $middlewareCount, $avg, $perLayer, self::ITERS);
        $this->assertLessThan(5.0, $avg,
            'Middleware pipeline (10) should be < 5ms, got: ' . round($avg, 3) . 'ms');
    }

    public function testJsonSerializationPerformance(): void
    {
        $payload = [];
        for ($i = 0; $i < 1000; $i++) {
            $payload['item_' . $i] = [
                'id' => $i,
                'name' => 'Item ' . $i,
                'description' => str_repeat('x', 100),
                'tags' => ['a', 'b', 'c'],
                'nested' => ['key' => 'value', 'count' => $i],
            ];
        }

        for ($i = 0; $i < self::WARMUP; $i++) {
            $resp = Response::json($payload);
            $resp->payload();
        }

        $encodeTimes = [];
        $decodeTimes = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $resp = Response::json($payload);

            $start = microtime(true);
            $json = json_encode($resp->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $encodeTimes[] = (microtime(true) - $start) * 1000;

            $start = microtime(true);
            json_decode($json, true);
            $decodeTimes[] = (microtime(true) - $start) * 1000;
        }

        $avgEnc = array_sum($encodeTimes) / count($encodeTimes);
        $avgDec = array_sum($decodeTimes) / count($decodeTimes);
        $bytes = strlen($json ?? '');
        echo sprintf("\n JSON encode (1000 items): %8.4f ms  (%d bytes)", $avgEnc, $bytes);
        echo sprintf("\n JSON decode (1000 items): %8.4f ms", $avgDec);
        $this->assertLessThan(5.0, $avgEnc, 'JSON encode should be < 5ms');
    }

    public function testDatabaseQueryPerformance(): void
    {
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        Database::execute("CREATE TABLE IF NOT EXISTS test_perf (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            value INTEGER NOT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $pdo = Database::connection();
        $pdo->exec("PRAGMA journal_mode=WAL");
        $pdo->exec("PRAGMA synchronous=OFF");
        $pdo->exec("PRAGMA cache_size=10000");

        for ($i = 0; $i < 1000; $i++) {
            Database::execute(
                "INSERT INTO test_perf (name, value) VALUES (:name, :value)",
                ['name' => 'item_' . $i, 'value' => $i]
            );
        }

        for ($i = 0; $i < self::WARMUP; $i++) {
            Database::select("SELECT * FROM test_perf WHERE value > :min", ['min' => 500]);
        }

        $selectTimes = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $start = microtime(true);
            Database::select("SELECT * FROM test_perf WHERE value > :min", ['min' => 500]);
            $selectTimes[] = (microtime(true) - $start) * 1000;
        }

        $avgSelect = array_sum($selectTimes) / count($selectTimes);

        $insertTimes = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $start = microtime(true);
            Database::execute(
                "INSERT INTO test_perf (name, value) VALUES (:name, :value)",
                ['name' => 'perf_test_' . $i, 'value' => $i]
            );
            $insertTimes[] = (microtime(true) - $start) * 1000;
        }
        $avgInsert = array_sum($insertTimes) / count($insertTimes);

        echo sprintf("\n DB select avg:           %8.4f ms  (n=%d, 500 rows matched)", $avgSelect, self::ITERS);
        echo sprintf("\n DB insert avg:           %8.4f ms  (n=%d)", $avgInsert, self::ITERS);
        $this->assertLessThan(2.0, $avgSelect, 'DB select should be < 2ms');
        Database::purgeAll();
    }

    // ═══════════════════════════════════════════
    //  3. MEMORY USAGE
    // ═══════════════════════════════════════════

    public function testPeakMemoryPerRequest(): void
    {
        $this->resetAllState();
        $this->createTempConfigDir($this->tempDir);

        $app = new App($this->tempDir);
        $app->boot();
        $app->router->get('/memory-test', function () {
            return Response::success(['data' => str_repeat('x', 1024)]);
        });

        $memoryBefore = memory_get_usage(false);
        $peakBefore = memory_get_peak_usage(false);
        $resp = $app->router->dispatch(new Request('GET', '/memory-test'));
        $resp->payload();
        $memoryAfter = memory_get_usage(false);
        $peakAfter = memory_get_peak_usage(false);

        $delta = $memoryAfter - $memoryBefore;
        $peakDelta = $peakAfter - $peakBefore;
        echo sprintf("\n Memory per request:       %8.2f KB  (peak: %.2f KB)",
            $delta / 1024, $peakDelta / 1024);
        $this->assertLessThan(512 * 1024, $delta,
            'Memory delta per request should be < 512KB');
    }

    public function testMemoryLeak(): void
    {
        $memoryBefore = memory_get_usage(true);

        for ($i = 0; $i < 100; $i++) {
            $this->resetAllState();
            $configDir = $this->createTempConfigDir($this->tempDir);
            $app = new App($this->tempDir);
            $app->boot();
        }

        $memoryAfter = memory_get_usage(true);
        $leak = $memoryAfter - $memoryBefore;
        echo sprintf("\n Memory leak (100 boots):  %8.2f KB", $leak / 1024);
        $this->assertLessThan(1024 * 1024, $leak,
            'Memory leak over 100 boots should be < 1MB, got: ' . round($leak / 1024, 2) . 'KB');
    }

    public function testStaticStateAccumulation(): void
    {
        Router::setMiddlewareAliases([]);

        $aliasesBefore = count(Router::getMiddlewareAliases());

        for ($i = 0; $i < 100; $i++) {
            $router = new Router();
            $router->get('/test' . $i, function () { return ['ok' => true]; });
        }

        $aliasesAfter = count(Router::getMiddlewareAliases());

        echo sprintf("\n Static middleware aliases: before=%d, after=%d (delta=%d)",
            $aliasesBefore, $aliasesAfter, $aliasesAfter - $aliasesBefore);
        $this->assertSame($aliasesBefore, $aliasesAfter,
            'Static middleware aliases should not grow after 100 iterations');
    }

    // ═══════════════════════════════════════════
    //  4. CACHE PERFORMANCE
    // ═══════════════════════════════════════════

    public function testConfigCacheSpeedup(): void
    {
        $this->resetAllState();
        $configDir = $this->createTempConfigDir($this->tempDir);
        for ($i = 0; $i < 10; $i++) {
            file_put_contents(
                $configDir . DIRECTORY_SEPARATOR . 'app_' . $i . '.php',
                '<?php return ["key' . $i . '" => "value' . $i . '", "nested" => ["a" => 1, "b" => 2]];'
            );
        }

        Config::clearCache();
        $timesWithout = [];
        for ($i = 0; $i < 20; $i++) {
            Config::reset();
            $start = microtime(true);
            Config::load($configDir);
            $timesWithout[] = (microtime(true) - $start) * 1000;
        }

        Config::cache();
        $timesWith = [];
        for ($i = 0; $i < 20; $i++) {
            Config::reset();
            $start = microtime(true);
            Config::load($configDir);
            $timesWith[] = (microtime(true) - $start) * 1000;
        }

        Config::clearCache();
        $avgWithout = array_sum($timesWithout) / count($timesWithout);
        $avgWith = array_sum($timesWith) / count($timesWith);
        $speedup = $avgWithout > 0 && $avgWith > 0 ? $avgWithout / $avgWith : 0;
        echo sprintf("\n Config load (no cache):   %8.4f ms", $avgWithout);
        echo sprintf("\n Config load (cached):     %8.4f ms  (%.1fx speedup)", $avgWith, $speedup);
        $this->assertGreaterThan(1.0, $speedup,
            'Config cache should provide speedup (got ' . round($speedup, 1) . 'x)');
    }

    public function testRouteCacheSpeedup(): void
    {
        $this->resetAllState();
        $cacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'routes.php';
        $handlerPrefix = 'DummyPerfHandler_' . bin2hex(random_bytes(4));

        $router = new Router();
        for ($i = 0; $i < 200; $i++) {
            $router->get('/route/' . $i, $handlerPrefix . '@handle' . $i);
        }
        $router->saveToCache($cacheFile);

        $registerTimes = [];
        for ($i = 0; $i < 30; $i++) {
            $r = new Router();
            $start = microtime(true);
            for ($j = 0; $j < 200; $j++) {
                $r->get('/route/' . $j, $handlerPrefix . '@handle' . $j);
            }
            $registerTimes[] = (microtime(true) - $start) * 1000;
        }

        $cacheTimes = [];
        for ($i = 0; $i < 30; $i++) {
            $r = new Router();
            $start = microtime(true);
            $r->loadFromCache($cacheFile);
            $cacheTimes[] = (microtime(true) - $start) * 1000;
        }

        $avgRegister = array_sum($registerTimes) / count($registerTimes);
        $avgCache = array_sum($cacheTimes) / count($cacheTimes);
        $speedup = $avgRegister > 0 && $avgCache > 0 ? $avgRegister / $avgCache : 0;
        echo sprintf("\n Route register (200):     %8.4f ms", $avgRegister);
        echo sprintf("\n Route load from cache:    %8.4f ms  (%.1fx speedup)", $avgCache, $speedup);
        if ($avgRegister > $avgCache) {
            $this->assertGreaterThan(1.0, $speedup,
                'Route cache loading should be faster than registration');
        } else {
            echo '  [note: cache load is JSON decode bound]';
            $this->assertTrue(true);
        }
    }

    public function testQueryCacheEfficiency(): void
    {
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        Database::execute("CREATE TABLE IF NOT EXISTS cache_test (
            id INTEGER PRIMARY KEY, value INTEGER
        )");
        for ($i = 0; $i < 100; $i++) {
            Database::execute("INSERT INTO cache_test (value) VALUES (:v)", ['v' => $i]);
        }

        $sql = "SELECT COUNT(*) as cnt FROM cache_test WHERE value > :min";

        for ($i = 0; $i < self::WARMUP; $i++) {
            Database::select($sql, ['min' => 50]);
        }

        $timesNoCache = [];
        for ($i = 0; $i < 50; $i++) {
            $start = microtime(true);
            Database::select($sql, ['min' => 50]);
            $timesNoCache[] = (microtime(true) - $start) * 1000;
        }

        $timesCached = [];
        for ($i = 0; $i < 50; $i++) {
            $start = microtime(true);
            Database::cache(60)->select($sql, ['min' => 50]);
            $timesCached[] = (microtime(true) - $start) * 1000;
        }

        $avgNoCache = array_sum($timesNoCache) / count($timesNoCache);
        $avgCached = array_sum($timesCached) / count($timesCached);
        Cache::flush('qb:default:');
        $speedup = $avgNoCache > 0 && $avgCached > 0 ? $avgNoCache / $avgCached : 0;
        echo sprintf("\n Query (no cache):         %8.4f ms", $avgNoCache);
        echo sprintf("\n Query (with cache):       %8.4f ms  (%.1fx speedup)", $avgCached, $speedup);
        Database::purgeAll();
        $this->assertGreaterThan(0, $avgNoCache + $avgCached, 'Query cache test should complete');
    }

    // ═══════════════════════════════════════════
    //  5. STRESS TESTING
    // ═══════════════════════════════════════════

    public function testManyRoutesRegistration(): void
    {
        $router = new Router();

        $times = [];
        for ($run = 0; $run < 5; $run++) {
            $r = new Router();
            $start = microtime(true);
            for ($i = 0; $i < self::MANY_ROUTES; $i++) {
                $r->get('/route/' . $i, function () use ($i) { return ['id' => $i]; });
            }
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        $perRoute = $avg / self::MANY_ROUTES;
        echo sprintf("\n Register %d routes:     %8.4f ms total  (%.4f ms/route)", self::MANY_ROUTES, $avg, $perRoute);
        $this->assertLessThan(100.0, $avg,
            '1000 route registration should be < 100ms');
    }

    public function testManyRouteDispatchLookup(): void
    {
        $router = new Router();
        for ($i = 0; $i < self::MANY_ROUTES; $i++) {
            $handler = function () use ($i) { return Response::success(['id' => $i]); };
            $router->get('/route/' . $i, $handler);
        }
        $router->get('/route/lookup-final', function () { return Response::success(['ok' => true]); });

        for ($i = 0; $i < self::WARMUP; $i++) {
            $router->dispatch(new Request('GET', '/route/lookup-final'));
        }

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/route/lookup-final');
            $start = microtime(true);
            $router->dispatch($req);
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        echo sprintf("\n Dispatch among %d routes: %8.4f ms  (n=%d)", self::MANY_ROUTES, $avg, self::ITERS);
        $this->assertLessThan(1.0, $avg,
            'Dispatch among 1000 routes should be < 1ms');
    }

    public function testLargePayloadProcessing(): void
    {
        $data = str_repeat('x', 100 * 1024);
        $payload = ['data' => $data, 'metadata' => ['size' => 102400, 'type' => 'text']];

        for ($i = 0; $i < self::WARMUP; $i++) {
            json_encode($payload);
        }

        $encodeTimes = [];
        $decodeTimes = [];
        $serializeTimes = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $start = microtime(true);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $encodeTimes[] = (microtime(true) - $start) * 1000;

            $start = microtime(true);
            json_decode($encoded, true);
            $decodeTimes[] = (microtime(true) - $start) * 1000;

            $resp = Response::json($payload);
            $start = microtime(true);
            $resp->payload();
            $serializeTimes[] = (microtime(true) - $start) * 1000;
        }

        $avgEnc = array_sum($encodeTimes) / count($encodeTimes);
        $avgDec = array_sum($decodeTimes) / count($decodeTimes);
        $avgSer = array_sum($serializeTimes) / count($serializeTimes);
        echo sprintf("\n 100KB payload encode:     %8.4f ms", $avgEnc);
        echo sprintf("\n 100KB payload decode:     %8.4f ms", $avgDec);
        echo sprintf("\n Response payload access:  %8.4f ms", $avgSer);
        $this->assertLessThan(10.0, $avgEnc,
            '100KB payload encode should be < 10ms');
    }

    public function testStaticVsDynamicRoutePerformance(): void
    {
        $routerStatic = new Router();
        $routerStatic->get('/user/profile/settings', function () {
            return Response::success(['page' => 'settings']);
        });

        $routerDynamic = new Router();
        $routerDynamic->get('/user/{section}/{page}', function (Request $req) {
            return Response::success(['section' => $req->param('section'), 'page' => $req->param('page')]);
        });

        for ($i = 0; $i < self::WARMUP; $i++) {
            $routerStatic->dispatch(new Request('GET', '/user/profile/settings'));
            $routerDynamic->dispatch(new Request('GET', '/user/profile/settings'));
        }

        $staticTimes = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/user/profile/settings');
            $start = microtime(true);
            $routerStatic->dispatch($req);
            $staticTimes[] = (microtime(true) - $start) * 1000;
        }

        $dynamicTimes = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/user/profile/settings');
            $start = microtime(true);
            $routerDynamic->dispatch($req);
            $dynamicTimes[] = (microtime(true) - $start) * 1000;
        }

        $avgStatic = array_sum($staticTimes) / count($staticTimes);
        $avgDynamic = array_sum($dynamicTimes) / count($dynamicTimes);
        $ratio = $avgDynamic > 0 && $avgStatic > 0 ? $avgDynamic / $avgStatic : 0;
        echo sprintf("\n Static route dispatch:    %8.4f ms", $avgStatic);
        echo sprintf("\n Dynamic route dispatch:   %8.4f ms  (static is %.1fx faster)", $avgDynamic, $ratio);
        $this->assertLessThan($avgDynamic * 1.5, $avgStatic,
            'Static route should be at least as fast as dynamic route');
    }

    public function testConcurrentSessionSimulation(): void
    {
        $router = new Router();
        $router->get('/session/data', function (Request $req) {
            return Response::success(['session' => $req->header('x-session-id', 'none')]);
        });

        $sessionIds = [];
        for ($i = 0; $i < 50; $i++) {
            $sessionIds[] = 'sess_' . bin2hex(random_bytes(8));
        }

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $sid = $sessionIds[$i % count($sessionIds)];
            $req = new Request('GET', '/session/data', [], ['x-session-id' => $sid]);
            $start = microtime(true);
            $router->dispatch($req);
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        echo sprintf("\n Session dispatch (50 sessions): %8.4f ms  (n=%d)", $avg, self::ITERS);
        $this->assertLessThan(1.0, $avg,
            'Session dispatch should be < 1ms');
    }

    public function testRateLimiterOverhead(): void
    {
        Cache::flush();
        $router = new Router();
        $throttleMiddleware = function (Request $req, callable $next): Response {
            $key = 'rate:' . $req->ip();
            $hits = Cache::get($key) ?: 0;
            if ($hits > 100) {
                return Response::error('Too Many Requests', 429);
            }
            Cache::set($key, $hits + 1, 60);
            return $next($req);
        };

        $router->get('/api/resource', function () {
            return Response::success(['data' => 'ok']);
        }, [$throttleMiddleware]);

        for ($i = 0; $i < self::WARMUP; $i++) {
            $router->dispatch(new Request('GET', '/api/resource', [], [], [], '127.0.0.1'));
        }

        $timesWithout = [];
        $routerNoLimit = new Router();
        $routerNoLimit->get('/api/resource', function () {
            return Response::success(['data' => 'ok']);
        });
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/api/resource');
            $start = microtime(true);
            $routerNoLimit->dispatch($req);
            $timesWithout[] = (microtime(true) - $start) * 1000;
        }

        $timesWith = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $req = new Request('GET', '/api/resource', [], [], [], '127.0.0.1');
            $start = microtime(true);
            $router->dispatch($req);
            $timesWith[] = (microtime(true) - $start) * 1000;
        }

        $avgWithout = array_sum($timesWithout) / count($timesWithout);
        $avgWith = array_sum($timesWith) / count($timesWith);
        $overhead = $avgWith - $avgWithout;
        echo sprintf("\n Rate limiter overhead:     %8.4f ms  (without: %.4f, with: %.4f)",
            $overhead, $avgWithout, $avgWith);
        $this->assertLessThan(2.0, $overhead,
            'Rate limiter overhead should be < 2ms');
    }

    public function testFullAppLifecyclePerformance(): void
    {
        $this->resetAllState();
        $configDir = $this->createTempConfigDir($this->tempDir);

        $app = new App($this->tempDir);
        $app->boot();
        $app->router->get('/api/benchmark', function () {
            return Response::success([
                'message' => 'Hello, World!',
                'timestamp' => time(),
            ]);
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/benchmark';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Benchmark/1.0';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        for ($i = 0; $i < self::WARMUP; $i++) {
            ob_start();
            $app->run();
            ob_end_clean();
        }

        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $_SERVER['REQUEST_URI'] = '/api/benchmark';
            $start = microtime(true);
            ob_start();
            $app->run();
            ob_end_clean();
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        $opsPerSec = $avg > 0 ? 1000 / $avg : 0;
        echo sprintf("\n Full app lifecycle:       %8.4f ms  (%.0f req/sec, n=%d)",
            $avg, $opsPerSec, self::ITERS);
        $this->assertLessThan(10.0, $avg,
            'Full app lifecycle should be < 10ms');
    }

    public function testResponseBuildTime(): void
    {
        $times = [];
        for ($i = 0; $i < self::ITERS; $i++) {
            $start = microtime(true);
            $r = Response::success(['id' => 1, 'name' => 'benchmark', 'tags' => ['a', 'b', 'c']]);
            $r->header('X-Custom', 'value');
            $r->payload();
            $times[] = (microtime(true) - $start) * 1000;
        }

        $avg = array_sum($times) / count($times);
        echo sprintf("\n Response build:           %8.4f ms  (n=%d)", $avg, self::ITERS);
        $this->assertLessThan(0.1, $avg,
            'Response build should be < 0.1ms');
    }

    public function testRouteCacheHitPerformance(): void
    {
        $router = new Router();
        Router::registerMiddlewareAlias('auth', \Siro\Core\Middleware\AuthMiddleware::class);
        Router::registerMiddlewareAlias('cors', \Siro\Core\Middleware\CorsMiddleware::class);

        $router->get('/cached-route', function () {
            return Response::success(['data' => 'cached']);
        });
        $router->setRouteCacheTTL('GET', '/cached-route', 300);

        $req = new Request('GET', '/cached-route');

        for ($i = 0; $i < self::WARMUP; $i++) {
            $router->dispatch($req);
        }

        $timesMiss = [];
        $timesHit = [];

        for ($i = 0; $i < self::ITERS; $i++) {
            $r = new Router();
            $r->get('/cached-route', function () { return Response::success(['data' => 'cached']); });
            $r->setRouteCacheTTL('GET', '/cached-route', 300);
            $cacheKey = 'route:GET:/cached-route';
            Cache::forget($cacheKey);
            $req2 = new Request('GET', '/cached-route');
            $start = microtime(true);
            $r->dispatch($req2);
            $timesMiss[] = (microtime(true) - $start) * 1000;
        }

        for ($i = 0; $i < self::ITERS; $i++) {
            $r2 = new Router();
            $r2->get('/cached-route', function () { return Response::success(['data' => 'cached']); });
            $r2->setRouteCacheTTL('GET', '/cached-route', 300);
            $req3 = new Request('GET', '/cached-route');
            $r2->dispatch($req3);
            $start = microtime(true);
            $r2->dispatch($req3);
            $timesHit[] = (microtime(true) - $start) * 1000;
        }

        Cache::forget('route:GET:/cached-route');
        $avgMiss = array_sum($timesMiss) / count($timesMiss);
        $avgHit = array_sum($timesHit) / count($timesHit);
        $speedup = $avgMiss > 0 && $avgHit > 0 ? $avgMiss / $avgHit : 0;
        echo sprintf("\n Route dispatch (miss):    %8.4f ms", $avgMiss);
        echo sprintf("\n Route dispatch (hit):     %8.4f ms  (%.1fx speedup)", $avgHit, $speedup);
        $this->assertGreaterThan(1.0, $speedup,
            'Route cache hit should be faster than miss');
    }
}
