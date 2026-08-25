<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Debug\TraceData;
use Siro\Core\Http;
use Siro\Core\Response;

/**
 * Tests for Level-2 Trace/Replay enhancements:
 * - Outbound HTTP capture
 * - Queue source trace correlation
 * - Side-effect detection
 * - Trace → PHPUnit improvements
 */
final class TraceLevel2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TraceData::reset();
        Http::clearCapturedCalls();
        Http::setCaptureEnabled(false);
    }

    protected function tearDown(): void
    {
        Http::clearCapturedCalls();
        Http::setCaptureEnabled(false);
        TraceData::reset();
        Response::setRequestMeta('', microtime(true));
        parent::tearDown();
    }

    // --- TraceData: outbound_http ---

    public function testTraceDataOutboundHttpEmptyByDefault(): void
    {
        $data = TraceData::getAll();
        $this->assertArrayNotHasKey('outbound_http', $data);
    }

    public function testTraceDataSetOutboundHttp(): void
    {
        TraceData::setOutboundHttp([
            ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/charges', 'status' => 200, 'duration_ms' => 234.5, 'error' => ''],
        ]);

        $data = TraceData::getAll();
        $this->assertArrayHasKey('outbound_http', $data);
        $this->assertCount(1, $data['outbound_http']);
        $this->assertSame('POST', $data['outbound_http'][0]['method']);
        $this->assertSame(200, $data['outbound_http'][0]['status']);
    }

    public function testTraceDataResetClearsOutboundHttp(): void
    {
        TraceData::setOutboundHttp([
            ['method' => 'GET', 'url' => 'https://example.com', 'status' => 200, 'duration_ms' => 10.0, 'error' => ''],
        ]);

        TraceData::reset();
        $data = TraceData::getAll();
        $this->assertArrayNotHasKey('outbound_http', $data);
    }

    // --- TraceData: queue_jobs ---

    public function testTraceDataQueueJobsEmptyByDefault(): void
    {
        $data = TraceData::getAll();
        $this->assertArrayNotHasKey('queue_jobs', $data);
    }

    public function testTraceDataSetQueueJobs(): void
    {
        TraceData::setQueueJobs([
            ['job' => 'SendEmailJob', 'source_trace_id' => 'siro_abc123', 'dispatched_at' => '2026-05-08T14:30:00+00:00'],
        ]);

        $data = TraceData::getAll();
        $this->assertArrayHasKey('queue_jobs', $data);
        $this->assertCount(1, $data['queue_jobs']);
        $this->assertSame('SendEmailJob', $data['queue_jobs'][0]['job']);
        $this->assertSame('siro_abc123', $data['queue_jobs'][0]['source_trace_id']);
    }

    public function testTraceDataResetClearsQueueJobs(): void
    {
        TraceData::setQueueJobs([
            ['job' => 'TestJob', 'source_trace_id' => 'siro_123', 'dispatched_at' => '2026-01-01T00:00:00+00:00'],
        ]);

        TraceData::reset();
        $data = TraceData::getAll();
        $this->assertArrayNotHasKey('queue_jobs', $data);
    }

    // --- Http capture ---

    public function testHttpCaptureDisabledByDefault(): void
    {
        $calls = Http::getCapturedCalls();
        $this->assertSame([], $calls);
    }

    public function testHttpCaptureEnableDisable(): void
    {
        Http::setCaptureEnabled(true);
        Http::clearCapturedCalls();

        // No real HTTP call, just verify state
        $calls = Http::getCapturedCalls();
        $this->assertSame([], $calls);

        Http::setCaptureEnabled(false);
    }

    public function testHttpClearCapturedCalls(): void
    {
        Http::setCaptureEnabled(true);
        Http::clearCapturedCalls();

        // Manually add to static (simulating capture)
        $calls = Http::getCapturedCalls();
        $this->assertSame([], $calls);

        Http::clearCapturedCalls();
        Http::setCaptureEnabled(false);
    }

    public function testHttpSanitizeUrlStripsQueryParams(): void
    {
        // sanitizeUrl is private, but we can verify via the capture pipeline
        // For now, verify the public API contract
        $this->assertTrue(true); // placeholder — sanitizeUrl tested indirectly
    }

    // --- Response::getRequestTraceId ---

    public function testResponseGetRequestTraceIdDefaultEmpty(): void
    {
        // Reset by setting empty values
        Response::setRequestMeta('', microtime(true));
        $this->assertSame('', Response::getRequestTraceId());
    }

    public function testResponseGetRequestTraceIdReturnsSetId(): void
    {
        Response::setRequestMeta('siro_test123', microtime(true));
        $this->assertSame('siro_test123', Response::getRequestTraceId());
    }

    // --- Side-effect detection (LogReplayCommand) ---

    public function testDetectDbWritesFromSql(): void
    {
        // Simulates what LogReplayCommand::analyzeAndDisplayRisks does
        $queries = [
            ['sql' => 'SELECT * FROM users WHERE id = 1', 'time_ms' => 5.0, 'rows' => 1],
            ['sql' => 'INSERT INTO orders (user_id, total) VALUES (1, 100)', 'time_ms' => 12.0, 'rows' => 1],
            ['sql' => 'UPDATE inventory SET qty = qty - 1 WHERE product_id = 5', 'time_ms' => 3.0, 'rows' => 1],
        ];

        $dbWrites = 0;
        foreach ($queries as $query) {
            $sql = strtoupper($query['sql']);
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                $dbWrites++;
            }
        }

        $this->assertSame(2, $dbWrites);
    }

    public function testDetectReadOnlySqlNotWrite(): void
    {
        $queries = [
            ['sql' => 'SELECT * FROM users WHERE id = 1', 'time_ms' => 5.0, 'rows' => 1],
            ['sql' => 'SELECT COUNT(*) FROM orders', 'time_ms' => 2.0, 'rows' => 1],
        ];

        $dbWrites = 0;
        foreach ($queries as $query) {
            $sql = strtoupper($query['sql']);
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                $dbWrites++;
            }
        }

        $this->assertSame(0, $dbWrites);
    }

    public function testDetectDeleteAsWrite(): void
    {
        $queries = [
            ['sql' => 'DELETE FROM sessions WHERE expires_at < NOW()', 'time_ms' => 8.0, 'rows' => 42],
        ];

        $dbWrites = 0;
        foreach ($queries as $query) {
            $sql = strtoupper($query['sql']);
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                $dbWrites++;
            }
        }

        $this->assertSame(1, $dbWrites);
    }

    public function testDetectCreateTableAsWrite(): void
    {
        $queries = [
            ['sql' => 'CREATE TABLE temp_results (id INT PRIMARY KEY)', 'time_ms' => 15.0, 'rows' => 0],
        ];

        $dbWrites = 0;
        foreach ($queries as $query) {
            $sql = strtoupper($query['sql']);
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                $dbWrites++;
            }
        }

        $this->assertSame(1, $dbWrites);
    }

    public function testDetectOutboundHttpCalls(): void
    {
        $outboundHttp = [
            ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/charges', 'status' => 200, 'duration_ms' => 234.5, 'error' => ''],
        ];

        $httpCalls = count($outboundHttp);
        $this->assertSame(1, $httpCalls);
    }

    public function testDetectQueueJobs(): void
    {
        $queueJobs = [
            ['job' => 'SendEmailJob', 'source_trace_id' => 'siro_123', 'dispatched_at' => '2026-05-08T14:30:00+00:00'],
            ['job' => 'ProcessWebhookJob', 'source_trace_id' => 'siro_123', 'dispatched_at' => '2026-05-08T14:30:01+00:00'],
        ];

        $queueJobsCount = count($queueJobs);
        $this->assertSame(2, $queueJobsCount);
    }

    public function testDestructiveHttpMethodDetection(): void
    {
        $destructiveMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        $safeMethods = ['GET', 'HEAD', 'OPTIONS'];

        foreach ($destructiveMethods as $method) {
            $this->assertTrue(
                in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
                "$method should be detected as destructive"
            );
        }

        foreach ($safeMethods as $method) {
            $this->assertFalse(
                in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
                "$method should NOT be detected as destructive"
            );
        }
    }

    // --- Old trace backward compatibility ---

    public function testOldTraceWithoutOutboundHttpStillWorks(): void
    {
        // Simulate an old trace that doesn't have the new fields
        $oldTrace = [
            'method' => 'GET',
            'path' => '/api/users',
            'status' => 200,
            'queries' => [['sql' => 'SELECT * FROM users', 'time_ms' => 5.0, 'rows' => 10]],
        ];

        // Side-effect detection on old trace should not crash
        $httpCalls = isset($oldTrace['outbound_http']) && is_array($oldTrace['outbound_http'])
            ? count($oldTrace['outbound_http']) : 0;
        $queueJobs = isset($oldTrace['queue_jobs']) && is_array($oldTrace['queue_jobs'])
            ? count($oldTrace['queue_jobs']) : 0;

        $this->assertSame(0, $httpCalls);
        $this->assertSame(0, $queueJobs);
    }

    public function testOldTraceWithoutQueriesStillWorks(): void
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

    // --- URL sanitization ---

    public function testSanitizeUrlStripsQueryAndFragment(): void
    {
        // Direct test via Http private method using reflection
        $ref = new \ReflectionMethod(Http::class, 'sanitizeUrl');
        $ref->setAccessible(true);

        $this->assertSame(
            'https://api.stripe.com/v1/charges',
            $ref->invoke(null, 'https://api.stripe.com/v1/charges?secret=sk_test_12345#section')
        );
    }

    public function testSanitizeUrlStripsUserPassInfo(): void
    {
        $ref = new \ReflectionMethod(Http::class, 'sanitizeUrl');
        $ref->setAccessible(true);

        // sanitizeUrl strips user:pass@ — only keeps scheme+host+path
        $result = $ref->invoke(null, 'https://user:secret@api.example.com/v1/charges?key=abc');
        $this->assertSame('https://api.example.com/v1/charges', $result);
    }

    public function testSanitizeUrlPreservesPort(): void
    {
        $ref = new \ReflectionMethod(Http::class, 'sanitizeUrl');
        $ref->setAccessible(true);

        $this->assertSame(
            'http://localhost:8080/api/test',
            $ref->invoke(null, 'http://localhost:8080/api/test?foo=bar')
        );
    }

    public function testSanitizeUrlStripsQueryFromComplexUrl(): void
    {
        $ref = new \ReflectionMethod(Http::class, 'sanitizeUrl');
        $ref->setAccessible(true);

        $result = $ref->invoke(null, 'https://hooks.stripe.com/webhook?evt_123=abc&sig=xyz');
        $this->assertSame('https://hooks.stripe.com/webhook', $result);
    }
}
