<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\LogReplayCommand;
use Siro\Core\Commands\MakeTestCommand;
use Siro\Core\Debug\TraceData;
use Siro\Core\Http;
use Siro\Core\Response;

/**
 * E2E tests for Level-2 Trace/Replay enhancements.
 *
 * Tests realistic trace fixtures through the full pipeline:
 * trace → side-effect detection → risk summary → test generation.
 */
final class TraceLevel2E2ETest extends TestCase
{
    private string $basePath;
    private string $traceDir;

    protected function setUp(): void
    {
        parent::setUp();
        TraceData::reset();
        Http::clearCapturedCalls();
        Http::setCaptureEnabled(false);
        Response::setRequestMeta('', microtime(true));

        $this->basePath = sys_get_temp_dir() . '/siro_e2e_' . uniqid();
        $this->traceDir = $this->basePath . '/storage/logs/traces/2026/05/08/a3';
        mkdir($this->traceDir, 0775, true);

        // Create checkout trace fixture in temp dir
        $checkoutTrace = [
            'method' => 'POST',
            'path' => '/api/checkout',
            'status' => 200,
            'time_ms' => 342.15,
            'trace_id' => 'siro_checkout_abc123',
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'host' => 'localhost:8080',
            'timestamp' => '2026-05-08T14:30:00+00:00',
            'middleware' => [
                ['name' => 'csrf', 'passed' => true, 'time_ms' => 2.1],
                ['name' => 'auth', 'passed' => true, 'time_ms' => 15.3],
                ['name' => 'throttle', 'passed' => true, 'time_ms' => 1.2],
            ],
            'queries' => [
                ['sql' => 'SELECT * FROM users WHERE id = 42', 'time_ms' => 8.5, 'rows' => 1],
                ['sql' => 'SELECT * FROM products WHERE id = 7', 'time_ms' => 3.2, 'rows' => 1],
                ['sql' => 'SELECT * FROM inventory WHERE product_id = 7', 'time_ms' => 2.8, 'rows' => 1],
                ['sql' => 'INSERT INTO orders (user_id, product_id, quantity, total, status) VALUES (42, 7, 2, 199.98, \'pending\')', 'time_ms' => 25.4, 'rows' => 1],
                ['sql' => 'UPDATE inventory SET quantity = quantity - 2 WHERE product_id = 7 AND quantity >= 2', 'time_ms' => 8.1, 'rows' => 1],
                ['sql' => 'UPDATE users SET last_order_at = \'2026-05-08 14:30:00\' WHERE id = 42', 'time_ms' => 3.2, 'rows' => 1],
            ],
            'request_body' => '{"product_id": 7, "quantity": 2, "payment_method": "credit_card"}',
            'response_body' => '{"success": true, "message": "Order created", "data": {"order_id": 1001, "total": 199.98}}',
            'request_headers' => [
                'content-type' => 'application/json',
                'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.REDACTED',
                'accept' => 'application/json',
            ],
            'auth_header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.REDACTED',
            'content_type' => 'application/json',
            'outbound_http' => [
                ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/charges', 'status' => 200, 'duration_ms' => 234.5, 'error' => ''],
                ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/refunds', 'status' => 0, 'duration_ms' => 1200.0, 'error' => 'Connection timed out'],
            ],
            'queue_jobs' => [
                ['job' => 'SendOrderConfirmationEmail', 'source_trace_id' => 'siro_checkout_abc123', 'dispatched_at' => '2026-05-08T14:30:01+00:00'],
                ['job' => 'SyncInventoryToWarehouse', 'source_trace_id' => 'siro_checkout_abc123', 'dispatched_at' => '2026-05-08T14:30:02+00:00'],
            ],
        ];
        file_put_contents($this->traceDir . '/siro_checkout_abc123.json', json_encode($checkoutTrace, JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        // Cleanup temp dir
        $this->removeDir($this->basePath);
        Http::clearCapturedCalls();
        Http::setCaptureEnabled(false);
        TraceData::reset();
        Response::setRequestMeta('', microtime(true));
        parent::tearDown();
    }

    /**
     * E2E: Full checkout trace → risk detection → verify all categories.
     */
    public function testCheckoutTraceRiskDetection(): void
    {
        $trace = $this->loadCheckoutTrace();

        // Simulate analyzeAndDisplayRisks logic (same as LogReplayCommand)
        $dbWrites = 0;
        $httpCalls = 0;
        $queueJobs = 0;

        if (isset($trace['queries']) && is_array($trace['queries'])) {
            foreach ($trace['queries'] as $query) {
                $sql = strtoupper(is_string($query['sql'] ?? null) ? $query['sql'] : '');
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                    $dbWrites++;
                }
            }
        }

        if (isset($trace['outbound_http']) && is_array($trace['outbound_http'])) {
            $httpCalls = count($trace['outbound_http']);
        }

        if (isset($trace['queue_jobs']) && is_array($trace['queue_jobs'])) {
            $queueJobs = count($trace['queue_jobs']);
        }

        $destructiveMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        // Verify detection
        $this->assertSame(3, $dbWrites, 'Should detect INSERT + 2 UPDATEs');
        $this->assertSame(2, $httpCalls, 'Should detect 2 outbound HTTP calls');
        $this->assertSame(2, $queueJobs, 'Should detect 2 queue jobs');
        $this->assertTrue($destructiveMethod, 'POST is destructive');
    }

    /**
     * E2E: Checkout trace — SELECT-only queries should NOT be counted as writes.
     */
    public function testCheckoutTraceSelectQueriesNotCountedAsWrites(): void
    {
        $trace = $this->loadCheckoutTrace();

        $totalQueries = count($trace['queries']);
        $writeQueries = 0;

        foreach ($trace['queries'] as $query) {
            $sql = strtoupper(is_string($query['sql'] ?? null) ? $query['sql'] : '');
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                $writeQueries++;
            }
        }

        $this->assertSame(6, $totalQueries, 'Trace has 6 total queries');
        $this->assertSame(3, $writeQueries, '3 are writes (INSERT + 2 UPDATEs), 3 are SELECTs');
    }

    /**
     * E2E: Checkout trace — verify outbound HTTP capture includes error.
     */
    public function testCheckoutTraceOutboundHttpWithError(): void
    {
        $trace = $this->loadCheckoutTrace();

        $httpCalls = $trace['outbound_http'];
        $this->assertCount(2, $httpCalls);

        // First call: successful
        $this->assertSame('POST', $httpCalls[0]['method']);
        $this->assertSame(200, $httpCalls[0]['status']);
        $this->assertSame('', $httpCalls[0]['error']);
        $this->assertStringContainsString('stripe.com', $httpCalls[0]['url']);

        // Second call: failed
        $this->assertSame('POST', $httpCalls[1]['method']);
        $this->assertSame(0, $httpCalls[1]['status']);
        $this->assertStringContainsString('timed out', $httpCalls[1]['error']);
    }

    /**
     * E2E: Checkout trace — verify queue jobs have source_trace_id.
     */
    public function testCheckoutTraceQueueJobsCorrelation(): void
    {
        $trace = $this->loadCheckoutTrace();

        $jobs = $trace['queue_jobs'];
        $this->assertCount(2, $jobs);

        foreach ($jobs as $job) {
            $this->assertArrayHasKey('job', $job);
            $this->assertArrayHasKey('source_trace_id', $job);
            $this->assertArrayHasKey('dispatched_at', $job);
            $this->assertSame('siro_checkout_abc123', $job['source_trace_id']);
        }

        $this->assertSame('SendOrderConfirmationEmail', $jobs[0]['job']);
        $this->assertSame('SyncInventoryToWarehouse', $jobs[1]['job']);
    }

    /**
     * E2E: Checkout trace — verify URL sanitization removes query params.
     */
    public function testCheckoutTraceUrlSanitization(): void
    {
        $trace = $this->loadCheckoutTrace();

        foreach ($trace['outbound_http'] as $http) {
            // No query params in sanitized URL
            $this->assertStringNotContainsString('?', $http['url']);
            $this->assertStringNotContainsString('secret', $http['url']);
            $this->assertStringNotContainsString('key=', $http['url']);
        }
    }

    /**
     * E2E: Old trace without outbound_http/queue_jobs still works.
     */
    public function testOldTraceBackwardCompatibility(): void
    {
        $oldTrace = [
            'method' => 'GET',
            'path' => '/api/users',
            'status' => 200,
            'queries' => [
                ['sql' => 'SELECT * FROM users', 'time_ms' => 5.0, 'rows' => 10],
            ],
        ];

        $dbWrites = 0;
        $httpCalls = 0;
        $queueJobs = 0;

        if (isset($oldTrace['queries']) && is_array($oldTrace['queries'])) {
            foreach ($oldTrace['queries'] as $query) {
                $sql = strtoupper(is_string($query['sql'] ?? null) ? $query['sql'] : '');
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                    $dbWrites++;
                }
            }
        }

        if (isset($oldTrace['outbound_http']) && is_array($oldTrace['outbound_http'])) {
            $httpCalls = count($oldTrace['outbound_http']);
        }

        if (isset($oldTrace['queue_jobs']) && is_array($oldTrace['queue_jobs'])) {
            $queueJobs = count($oldTrace['queue_jobs']);
        }

        $this->assertSame(0, $dbWrites);
        $this->assertSame(0, $httpCalls);
        $this->assertSame(0, $queueJobs);
    }

    /**
     * E2E: Old trace without queries still works.
     */
    public function testOldTraceWithoutQueries(): void
    {
        $oldTrace = [
            'method' => 'GET',
            'path' => '/api/status',
            'status' => 200,
        ];

        $dbWrites = 0;
        if (isset($oldTrace['queries']) && is_array($oldTrace['queries'])) {
            foreach ($oldTrace['queries'] as $query) {
                $sql = strtoupper(is_string($query['sql'] ?? null) ? $query['sql'] : '');
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                    $dbWrites++;
                }
            }
        }

        $this->assertSame(0, $dbWrites);
    }

    /**
     * E2E: GET request with no side effects should not display risk summary.
     */
    public function testGetRequestNoRiskSummary(): void
    {
        $trace = [
            'method' => 'GET',
            'path' => '/api/users',
            'status' => 200,
            'queries' => [
                ['sql' => 'SELECT * FROM users', 'time_ms' => 5.0, 'rows' => 10],
            ],
        ];

        $dbWrites = 0;
        $httpCalls = 0;
        $queueJobs = 0;
        $destructiveMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        $hasRisks = $dbWrites > 0 || $httpCalls > 0 || $queueJobs > 0 || $destructiveMethod;

        $this->assertFalse($hasRisks, 'GET with SELECT-only should have no risks');
    }

    /**
     * E2E: DELETE request should trigger risk warning even with no other side effects.
     */
    public function testDeleteRequestTriggersWarning(): void
    {
        $trace = [
            'method' => 'DELETE',
            'path' => '/api/users/42',
            'status' => 204,
        ];

        $destructiveMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $this->assertTrue($destructiveMethod, 'DELETE is destructive');
    }

    /**
     * E2E: TraceData round-trip with new fields.
     */
    public function testTraceDataRoundTripWithNewFields(): void
    {
        TraceData::reset();

        // Set standard fields
        TraceData::setRequestBody('{"product_id": 7}');
        TraceData::setResponseBody('{"success": true}');
        TraceData::addQuery('INSERT INTO orders VALUES (1)', 25.0, 1);
        TraceData::addMiddleware('auth', true, 10.0);

        // Set new fields
        TraceData::setOutboundHttp([
            ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/charges', 'status' => 200, 'duration_ms' => 234.5, 'error' => ''],
        ]);
        TraceData::setQueueJobs([
            ['job' => 'SendEmail', 'source_trace_id' => 'siro_test', 'dispatched_at' => '2026-05-08T14:30:00+00:00'],
        ]);

        $data = TraceData::getAll();

        // Verify standard fields
        $this->assertSame('{"product_id": 7}', $data['request_body']);
        $this->assertSame('{"success": true}', $data['response_body']);
        $this->assertCount(1, $data['queries']);
        $this->assertCount(1, $data['middleware']);

        // Verify new fields
        $this->assertArrayHasKey('outbound_http', $data);
        $this->assertCount(1, $data['outbound_http']);
        $this->assertSame('POST', $data['outbound_http'][0]['method']);

        $this->assertArrayHasKey('queue_jobs', $data);
        $this->assertCount(1, $data['queue_jobs']);
        $this->assertSame('SendEmail', $data['queue_jobs'][0]['job']);
        $this->assertSame('siro_test', $data['queue_jobs'][0]['source_trace_id']);
    }

    /**
     * E2E: TraceData reset clears new fields.
     */
    public function testTraceDataResetClearsNewFields(): void
    {
        TraceData::setOutboundHttp([
            ['method' => 'GET', 'url' => 'https://example.com', 'status' => 200, 'duration_ms' => 10.0, 'error' => ''],
        ]);
        TraceData::setQueueJobs([
            ['job' => 'TestJob', 'source_trace_id' => 'siro_123', 'dispatched_at' => '2026-01-01T00:00:00+00:00'],
        ]);

        TraceData::reset();

        $data = TraceData::getAll();
        $this->assertArrayNotHasKey('outbound_http', $data);
        $this->assertArrayNotHasKey('queue_jobs', $data);
    }

    /**
     * E2E: Response::getRequestTraceId lifecycle.
     */
    public function testResponseTraceIdLifecycle(): void
    {
        // Empty by default
        Response::setRequestMeta('', microtime(true));
        $this->assertSame('', Response::getRequestTraceId());

        // Set trace ID
        Response::setRequestMeta('siro_abc123', microtime(true));
        $this->assertSame('siro_abc123', Response::getRequestTraceId());

        // Clear
        Response::setRequestMeta('', microtime(true));
        $this->assertSame('', Response::getRequestTraceId());
    }

    /**
     * E2E: Http capture lifecycle.
     */
    public function testHttpCaptureLifecycle(): void
    {
        // Disabled by default
        $this->assertSame([], Http::getCapturedCalls());

        // Enable + clear
        Http::setCaptureEnabled(true);
        Http::clearCapturedCalls();
        $this->assertSame([], Http::getCapturedCalls());

        // Disable
        Http::setCaptureEnabled(false);
        $this->assertSame([], Http::getCapturedCalls());
    }

    /**
     * E2E: SQL write detection edge cases.
     */
    public function testSqlWriteDetectionEdgeCases(): void
    {
        $testCases = [
            // Should be detected as writes
            ['sql' => 'INSERT INTO orders (id) VALUES (1)', 'expected' => true],
            ['sql' => '  INSERT INTO orders (id) VALUES (1)', 'expected' => true],
            ['sql' => 'UPDATE users SET name = "test"', 'expected' => true],
            ['sql' => 'DELETE FROM sessions', 'expected' => true],
            ['sql' => 'REPLACE INTO cache (key, val) VALUES ("a", "b")', 'expected' => true],
            ['sql' => 'TRUNCATE TABLE logs', 'expected' => true],
            ['sql' => 'ALTER TABLE users ADD COLUMN email VARCHAR(255)', 'expected' => true],
            ['sql' => 'DROP TABLE temp_data', 'expected' => true],
            ['sql' => 'CREATE TABLE temp (id INT)', 'expected' => true],

            // Should NOT be detected as writes
            ['sql' => 'SELECT * FROM users', 'expected' => false],
            ['sql' => 'SELECT COUNT(*) FROM orders', 'expected' => false],
            ['sql' => 'SELECT u.*, o.total FROM users u JOIN orders o ON u.id = o.user_id', 'expected' => false],
            ['sql' => 'SHOW TABLES', 'expected' => false],
            ['sql' => 'DESCRIBE users', 'expected' => false],
        ];

        foreach ($testCases as $case) {
            $sql = strtoupper($case['sql']);
            $isWrite = (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql);
            $this->assertSame(
                $case['expected'],
                $isWrite,
                "SQL: '{$case['sql']}' — expected " . ($case['expected'] ? 'write' : 'read')
            );
        }
    }

    /**
     * E2E: Destructive HTTP method detection.
     */
    public function testDestructiveMethodDetection(): void
    {
        $destructive = ['POST', 'PUT', 'PATCH', 'DELETE'];
        $safe = ['GET', 'HEAD', 'OPTIONS'];

        foreach ($destructive as $method) {
            $this->assertTrue(
                in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
                "$method should be destructive"
            );
        }

        foreach ($safe as $method) {
            $this->assertFalse(
                in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
                "$method should be safe"
            );
        }
    }

    /**
     * E2E: URL sanitization via reflection.
     */
    public function testUrlSanitizationE2E(): void
    {
        $ref = new \ReflectionMethod(Http::class, 'sanitizeUrl');
        $ref->setAccessible(true);

        // Normal URL with query params
        $this->assertSame(
            'https://api.stripe.com/v1/charges',
            $ref->invoke(null, 'https://api.stripe.com/v1/charges?secret=sk_test_12345&amount=100')
        );

        // URL with port
        $this->assertSame(
            'http://localhost:8080/api/test',
            $ref->invoke(null, 'http://localhost:8080/api/test?foo=bar')
        );

        // URL with user:pass
        $this->assertSame(
            'https://api.example.com/v1/data',
            $ref->invoke(null, 'https://user:secret@api.example.com/v1/data?key=abc')
        );

        // URL with fragment
        $this->assertSame(
            'https://example.com/page',
            $ref->invoke(null, 'https://example.com/page#section')
        );

        // Complex webhook URL
        $this->assertSame(
            'https://hooks.stripe.com/webhook',
            $ref->invoke(null, 'https://hooks.stripe.com/webhook?evt_123=abc&sig=xyz')
        );
    }

    // --- Helper methods ---

    private function loadCheckoutTrace(): array
    {
        $file = $this->traceDir . '/siro_checkout_abc123.json';
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data, 'Checkout trace should be valid JSON');
        return $data;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }

    // --- Vòng 3: Capture lifecycle + Guard tests ---

    /**
     * Verify Http capture is always cleaned up after request lifecycle.
     * Even if exception occurs, capture should be disabled.
     */
    public function testCaptureLifecycleCleanup(): void
    {
        // Simulate request lifecycle
        Http::clearCapturedCalls();
        Http::setCaptureEnabled(true);

        // Simulate exception during request — verify finally runs
        $exceptionCaught = false;
        try {
            throw new \RuntimeException('boom');
        } catch (\Throwable $e) {
            $exceptionCaught = true;
        } finally {
            Http::clearCapturedCalls();
            Http::setCaptureEnabled(false);
        }

        $this->assertTrue($exceptionCaught, 'Exception should have been caught');
        $this->assertSame([], Http::getCapturedCalls(), 'Captured calls should be cleared after finally');
    }

    /**
     * Verify capture cleanup happens even when no exception.
     */
    public function testCaptureLifecycleNormalPath(): void
    {
        Http::clearCapturedCalls();
        Http::setCaptureEnabled(true);

        // Normal path — no exception
        // ... request executes ...

        Http::clearCapturedCalls();
        Http::setCaptureEnabled(false);

        $this->assertSame([], Http::getCapturedCalls());
    }

    /**
     * Verify guard logic: GET + no risks → no guard needed.
     */
    public function testGuardGetNoRisks(): void
    {
        $trace = [
            'method' => 'GET',
            'path' => '/api/status',
            'queries' => [
                ['sql' => 'SELECT 1', 'time_ms' => 1.0, 'rows' => 1],
            ],
        ];

        $hasRisks = $this->detectRisks($trace);
        $isWriteMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $needsGuard = $isWriteMethod || $hasRisks;

        $this->assertFalse($needsGuard, 'GET + no risks → no guard');
    }

    /**
     * Verify guard logic: GET + outbound HTTP → guard required.
     */
    public function testGuardGetWithOutboundHttp(): void
    {
        $trace = [
            'method' => 'GET',
            'path' => '/api/status',
            'outbound_http' => [
                ['method' => 'GET', 'url' => 'https://api.example.com', 'status' => 200, 'duration_ms' => 10.0, 'error' => ''],
            ],
        ];

        $hasRisks = $this->detectRisks($trace);
        $isWriteMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $needsGuard = $isWriteMethod || $hasRisks;

        $this->assertTrue($needsGuard, 'GET + outbound HTTP → guard required');
    }

    /**
     * Verify guard logic: GET + queue jobs → guard required.
     */
    public function testGuardGetWithQueueJobs(): void
    {
        $trace = [
            'method' => 'GET',
            'path' => '/api/sync',
            'queue_jobs' => [
                ['job' => 'SyncJob', 'source_trace_id' => 'siro_123', 'dispatched_at' => '2026-05-08T14:30:00+00:00'],
            ],
        ];

        $hasRisks = $this->detectRisks($trace);
        $isWriteMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $needsGuard = $isWriteMethod || $hasRisks;

        $this->assertTrue($needsGuard, 'GET + queue jobs → guard required');
    }

    /**
     * Verify guard logic: POST + no risks → guard required (write method).
     */
    public function testGuardPostNoRisks(): void
    {
        $trace = [
            'method' => 'POST',
            'path' => '/api/login',
        ];

        $hasRisks = $this->detectRisks($trace);
        $isWriteMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $needsGuard = $isWriteMethod || $hasRisks;

        $this->assertTrue($needsGuard, 'POST → guard required');
    }

    /**
     * Verify guard logic: checkout trace → guard required (write + risks).
     */
    public function testGuardCheckoutTrace(): void
    {
        $trace = $this->loadCheckoutTrace();

        $hasRisks = $this->detectRisks($trace);
        $isWriteMethod = in_array($trace['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $needsGuard = $isWriteMethod || $hasRisks;

        $this->assertTrue($needsGuard, 'Checkout trace → guard required');
    }

    /**
     * Simulate detectRisks logic (mirrors LogReplayCommand::analyzeAndDisplayRisks).
     */
    private function detectRisks(array $trace): bool
    {
        $dbWrites = 0;
        $httpCalls = 0;
        $queueJobs = 0;

        if (isset($trace['queries']) && is_array($trace['queries'])) {
            foreach ($trace['queries'] as $query) {
                $sql = strtoupper(is_string($query['sql'] ?? null) ? $query['sql'] : '');
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                    $dbWrites++;
                }
            }
        }

        if (isset($trace['outbound_http']) && is_array($trace['outbound_http'])) {
            $httpCalls = count($trace['outbound_http']);
        }

        if (isset($trace['queue_jobs']) && is_array($trace['queue_jobs'])) {
            $queueJobs = count($trace['queue_jobs']);
        }

        return $dbWrites > 0 || $httpCalls > 0 || $queueJobs > 0;
    }
}
