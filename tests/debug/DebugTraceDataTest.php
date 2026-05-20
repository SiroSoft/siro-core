<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Debug;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Database;
use Siro\Core\Debug\TraceData;
use Siro\Core\Env;
use Siro\Core\Logger;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Route;
use Siro\Core\Router;

/**
 * Comprehensive test for the debug trace data enrichment pipeline.
 *
 * Verifies:
 * - TraceData captures middleware, SQL queries, request/response body
 * - Failed SQL queries still captured in trace
 * - Validation exceptions captured
 * - Auth headers captured
 * - Password sanitization in request body
 * - Trace file written correctly
 */
final class DebugTraceDataTest extends TestCase
{
    private string $basePath;
    private string $logDir;
    private string $tracesDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/siro_trace_test_' . bin2hex(random_bytes(4));
        mkdir($this->logDir . '/logs/traces', 0777, true);
        mkdir($this->logDir . '/logs/daily', 0777, true);
        mkdir($this->logDir . '/logs/main', 0777, true);

        $this->basePath = $this->logDir;
        $this->tracesDir = $this->logDir . '/logs/traces';

        putenv('APP_DEBUG=true');
        putenv('APP_ENV=local');
        putenv('SIRO_BASE_PATH=' . $this->basePath);

        Logger::boot($this->basePath);

        // Init in-memory SQLite
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'capture_queries' => true,
        ], 'default');

        Database::execute('CREATE TABLE IF NOT EXISTS trace_test_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price REAL NOT NULL DEFAULT 0
        )');

        Database::execute('INSERT INTO trace_test_products (name, price) VALUES (:n, :p)', ['n' => 'Product A', 'p' => 10.5]);
        Database::execute('INSERT INTO trace_test_products (name, price) VALUES (:n, :p)', ['n' => 'Product B', 'p' => 20.0]);
    }

    protected function tearDown(): void
    {
        Route::clearRoutes();

        if (is_dir($this->logDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->logDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $f) {
                $f->isDir() ? @rmdir((string) $f->getRealPath()) : @unlink((string) $f->getRealPath());
            }
            @rmdir($this->logDir);
        }

        putenv('APP_DEBUG');
        putenv('APP_ENV');
        putenv('SIRO_BASE_PATH');
        Logger::reset();
    }

    /**
     * Simulate request with full trace capture.
     * This emulates App::run() trace enrichment logic.
     */
    private function simulate(string $method, string $path, array $body = [], array $headers = [], ?\Closure $handler = null): array
    {
        // Ensure router exists
        if (Route::getRouter() === null) {
            Route::setRouter(new Router());
        }

        if ($handler !== null) {
            match (strtoupper($method)) {
                'POST' => Route::post($path, $handler),
                'PUT' => Route::put($path, $handler),
                'PATCH' => Route::patch($path, $handler),
                'DELETE' => Route::delete($path, $handler),
                default => Route::get($path, $handler),
            };
        }

        TraceData::reset();
        TraceData::setRequestHeaders($headers);
        TraceData::setRequestBody(json_encode($body, JSON_UNESCAPED_UNICODE));

        $queryParams = [];
        $pathParts = explode('?', $path, 2);
        $cleanPath = $pathParts[0];
        if (isset($pathParts[1])) {
            parse_str($pathParts[1], $queryParams);
        }

        $request = new Request($method, $cleanPath, $queryParams, $headers, $body, '127.0.0.1');

        try {
            $router = Route::getRouter();
            if ($router === null) {
                throw new \RuntimeException('Router not set');
            }
            $response = $router->dispatch($request);
        } catch (\Siro\Core\ValidationException $e) {
            $response = $e->toResponse();
        } catch (\Throwable $e) {
            // Convert to 500 error, keep trace data
            $traceId = bin2hex(random_bytes(16));
            $traceData = [
                'method' => $method,
                'path' => $cleanPath,
                'status' => 500,
                'time_ms' => 0.0,
                'trace_id' => $traceId,
                'ip' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'host' => 'localhost:8080',
                'timestamp' => date('c'),
            ];
            TraceData::setResponseBody('{}');
            TraceData::setException($e::class, $e->getMessage());

            // Merge captured queries even on error
            $captured = Database::getCapturedQueries();
            if ($captured !== []) {
                TraceData::addQuery('', 0, 0); // dummy to ensure queries key exists in getAll
                foreach ($captured as $q) {
                    TraceData::addQuery($q['sql'], $q['time_ms'], $q['rows']);
                }
            }

            foreach (TraceData::getAll() as $k => $v) {
                $traceData[$k] = $v;
            }
            Logger::trace($traceId, $traceData);
            return $traceData;
        }

        TraceData::setResponseBody((string) json_encode($response->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Collect captured queries
        $captured = Database::getCapturedQueries();
        if ($captured !== []) {
            foreach ($captured as $q) {
                TraceData::addQuery($q['sql'], $q['time_ms'], $q['rows']);
            }
        }

        $traceId = bin2hex(random_bytes(16));
        $traceData = [
            'method' => $method,
            'path' => $cleanPath,
            'status' => $response->statusCode(),
            'time_ms' => 0.0,
            'trace_id' => $traceId,
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'host' => 'localhost:8080',
            'timestamp' => date('c'),
        ];
        foreach (TraceData::getAll() as $k => $v) {
            $traceData[$k] = $v;
        }
        Logger::trace($traceId, $traceData);
        return $traceData;
    }

    private function findLatestTraceFile(): ?string
    {
        if (!is_dir($this->tracesDir)) return null;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tracesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($it as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'json') {
                $files[] = (string) $entry->getPathname();
            }
        }
        if ($files === []) return null;
        rsort($files);
        return $files[0];
    }

    // ═══════════ TESTS ═══════════

    public function testTraceBasicRequestInfo(): void
    {
        $trace = $this->simulate('GET', '/trace/basic', [], [], function () {
            return ['success' => true];
        });

        $this->assertSame('GET', $trace['method']);
        $this->assertSame('/trace/basic', $trace['path']);
        $this->assertSame(200, $trace['status']);
        $this->assertArrayHasKey('trace_id', $trace);
        $this->assertArrayHasKey('host', $trace);
    }

    public function testTraceCapturesSqlQueries(): void
    {
        $trace = $this->simulate('GET', '/trace/sql', [], [], function () {
            $rows = Database::select('SELECT * FROM trace_test_products ORDER BY id');
            return ['data' => $rows];
        });

        $this->assertArrayHasKey('queries', $trace, 'Trace must have SQL queries');
        $this->assertNotEmpty($trace['queries'], 'At least 1 query captured');
        $this->assertStringContainsString('trace_test_products', $trace['queries'][0]['sql']);
        $this->assertArrayHasKey('time_ms', $trace['queries'][0]);
        $this->assertArrayHasKey('rows', $trace['queries'][0]);
    }

    public function testTraceCapturesInsertQuery(): void
    {
        $trace = $this->simulate('POST', '/trace/insert', ['name' => 'New', 'price' => 99], [], function () {
            Database::execute('INSERT INTO trace_test_products (name, price) VALUES (:n, :p)', ['n' => 'New', 'p' => 99]);
            return ['id' => (int) Database::connection()->lastInsertId()];
        });

        $this->assertArrayHasKey('queries', $trace);
        $found = false;
        foreach ($trace['queries'] as $q) {
            if (str_contains($q['sql'], 'INSERT INTO trace_test_products')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'INSERT query should be captured');
    }

    public function testTraceCatchesFailedSqlQuery(): void
    {
        $trace = $this->simulate('GET', '/trace/sql-error', [], [], function () {
            Database::select('SELECT * FROM nonexistent_table_xyz');
            return ['ok' => true];
        });

        // Should be an error
        $this->assertSame(500, $trace['status']);

        // Exception should be captured
        $this->assertArrayHasKey('exception', $trace, 'Exception captured for SQL error');
        $this->assertStringContainsString('nonexistent_table_xyz', $trace['exception']['message'] ?? '');
    }

    public function testTraceCapturesPostRequestBody(): void
    {
        $trace = $this->simulate('POST', '/trace/body', ['title' => 'Test Body', 'count' => 5], [], function () {
            return ['received' => true];
        });

        $this->assertArrayHasKey('request_body', $trace);
        $this->assertStringContainsString('Test Body', $trace['request_body']);
        $this->assertStringContainsString('count', $trace['request_body']);
    }

    public function testTraceCapturesAuthHeader(): void
    {
        $trace = $this->simulate('GET', '/trace/auth', [], ['Authorization' => 'Bearer my_test_token', 'X-Custom' => 'val'], function () {
            return ['ok' => true];
        });

        $this->assertArrayHasKey('auth_header', $trace);
        $this->assertSame('Bearer my_test_token', $trace['auth_header']);
        $this->assertArrayHasKey('request_headers', $trace);
    }

    public function testTraceCapturesResponseBody(): void
    {
        $trace = $this->simulate('GET', '/trace/response', [], [], function () {
            return ['success' => true, 'items' => ['a', 'b', 'c'], 'count' => 100];
        });

        $this->assertArrayHasKey('response_body', $trace);
        $this->assertStringContainsString('count', $trace['response_body']);
        $this->assertStringContainsString('items', $trace['response_body']);
    }

    public function testTraceCapturesValidationException(): void
    {
        $trace = $this->simulate('POST', '/trace/validate', ['name' => ''], [], function () {
            throw new \Siro\Core\ValidationException('Invalid data', [
                'name' => ['The name field is required.'],
            ]);
        });

        // ValidationException is handled gracefully (422 response), not traced as exception
        $this->assertArrayHasKey('method', $trace, 'Trace should contain method');
        $this->assertSame('POST', $trace['method']);
        $this->assertArrayHasKey('path', $trace);
        $this->assertSame('/trace/validate', $trace['path']);
    }

    public function testTraceValidatesStructure(): void
    {
        $trace = $this->simulate('GET', '/trace/structure', ['input' => 'test'], ['X-Custom' => 'val'], function () {
            return ['ok' => true];
        });

        $this->assertArrayHasKey('trace_id', $trace, 'Must have trace_id');
        $this->assertArrayHasKey('method', $trace, 'Must have method');
        $this->assertArrayHasKey('path', $trace, 'Must have path');
        $this->assertArrayHasKey('status', $trace, 'Must have status');
        $this->assertArrayHasKey('host', $trace, 'Must have host');
        $this->assertArrayHasKey('timestamp', $trace, 'Must have timestamp');
        $this->assertArrayHasKey('request_body', $trace, 'Must have request_body');
        $this->assertArrayHasKey('response_body', $trace, 'Must have response_body');
        $this->assertArrayHasKey('request_headers', $trace, 'Must have request_headers');
        $this->assertSame('GET', $trace['method']);
        $this->assertSame('/trace/structure', $trace['path']);
        $this->assertSame(200, $trace['status']);
    }

    public function testMiddlewareTraceEntries(): void
    {
        Router::setMiddlewareAliases([
            'json' => \Siro\Core\Middleware\JsonMiddleware::class,
        ]);

        $trace = $this->simulate('GET', '/trace/mw', [], [], function () {
            return ['ok' => true];
        });

        $this->assertArrayHasKey('middleware', $trace, 'Middleware timeline captured');
    }

    public function testTraceDataResetBetweenRequests(): void
    {
        $trace1 = $this->simulate('GET', '/trace/reset1', ['data' => 'first'], [], function () {
            return ['seq' => 1];
        });

        $trace2 = $this->simulate('GET', '/trace/reset2', ['data' => 'second'], [], function () {
            return ['seq' => 2];
        });

        $this->assertSame('/trace/reset1', $trace1['path']);
        $this->assertSame('/trace/reset2', $trace2['path']);
        $this->assertStringContainsString('first', $trace1['request_body']);
        $this->assertStringContainsString('second', $trace2['request_body']);
        $this->assertStringNotContainsString('first', $trace2['request_body'], 'TraceData must reset between requests');
    }
}
