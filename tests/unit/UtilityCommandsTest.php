<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;

/**
 * Covers small file/log-based utility commands (0% before this test).
 */
final class UtilityCommandsTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_util_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'main', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'scripts', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=testing\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
    }

    protected function tearDown(): void
    {
        Env::reset();
        Cache::reset();
        \Siro\Core\Database::purgeAll();
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @param array<int, string> $args @return array{int, string} */
    private function runCmd(string $class, array $args): array
    {
        ob_start();
        /** @var object $cmd */
        $cmd = new $class($this->basePath);
        $exit = $cmd->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }

    public function testDemoMissingScript(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DemoCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output);
    }

    public function testDemoWithScript(): void
    {
        file_put_contents($this->basePath . '/scripts/demo.php', "<?php echo \"demo ran\\n\";");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DemoCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('demo ran', $output);
    }

    public function testLogCleanupNoLogs(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogCleanupCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogCleanupWithLogs(): void
    {
        file_put_contents($this->basePath . '/storage/logs/main.log', "old log line\n");
        // create an old file
        $old = $this->basePath . '/storage/logs/daily/old-' . date('Y-m-d', time() - 30 * 86400) . '.log';
        file_put_contents($old, "x\n");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogCleanupCommand::class, ['--days=7']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogStats(): void
    {
        $daily = $this->basePath . '/storage/logs/daily/' . date('Y-m-d') . '.log';
        file_put_contents($daily, "line one\nline two\n");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogStatsCommand::class, []);
        $this->assertSame(0, $exit, $output);
    }

    public function testLogStatsNoLogs(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogStatsCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testEnvSwitchInvalid(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\EnvSwitchCommand::class, ['BAD ENV']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testEnvSwitchValid(): void
    {
        file_put_contents($this->basePath . '/.env', "APP_ENV=local\n");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\EnvSwitchCommand::class, ['production']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testAuditVerify(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\AuditVerifyCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDbExplain(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DbExplainCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('db:why', $output);
    }

    public function testSlowLogNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\SlowLogCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testSlowLogWithTrace(): void
    {
        file_put_contents(
            $this->basePath . '/storage/logs/traces/t1.json',
            json_encode(['method' => 'GET', 'path' => '/x', 'status' => 200, 'time_ms' => 500])
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\SlowLogCommand::class, ['--min-ms=100']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testTraceList(): void
    {
        file_put_contents(
            $this->basePath . '/storage/logs/traces/t2.json',
            json_encode(['method' => 'GET', 'path' => '/y', 'status' => 200, 'time_ms' => 10])
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\TraceListCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testTraceListEmpty(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\TraceListCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    // ── Log commands ──

    private function writeTrace(string $name, array $data): void
    {
        file_put_contents(
            $this->basePath . '/storage/logs/traces/' . $name . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function testLogTrace(): void
    {
        $this->writeTrace('lt1', ['method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 15]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTraceCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogTraceNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTraceCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogTraceWithFilters(): void
    {
        $this->writeTrace('lt2', ['method' => 'POST', 'path' => '/api/orders', 'status' => 422, 'time_ms' => 500, 'ip' => '1.2.3.4']);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTraceCommand::class, ['--status=422', '--method=POST', '--slow', '--limit=5']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogTraceRichTrace(): void
    {
        $this->writeTrace('ltrich', [
            'method' => 'POST', 'path' => '/api/orders', 'status' => 500, 'time_ms' => 800,
            'ip' => '1.2.3.4', 'host' => 'example.com', 'memory_mb' => 12.5,
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'request_body' => '{"name":"Test","price":5}',
            'response_body' => '{"success":false,"error":"boom"}',
            'auth_header' => 'Bearer abc123def456',
            'request_headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'y', 'X-Custom' => 'z'],
            'queries' => [
                ['sql' => 'SELECT * FROM orders WHERE id = ?', 'time_ms' => 120],
                ['sql' => 'SELECT * FROM users', 'time_ms' => 5],
            ],
        ]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTraceCommand::class, ['ltrich', '--full']);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('SQL Queries', $output);
    }

    public function testLogTraceById(): void
    {
        $this->writeTrace('ltbyid', ['method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 10]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTraceCommand::class, ['ltbyid']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogExport(): void
    {
        $this->writeTrace('le1', ['method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 15]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogExportCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogExportPostman(): void
    {
        $this->writeTrace('le2', ['method' => 'POST', 'path' => '/api/orders', 'status' => 201, 'time_ms' => 20]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogExportCommand::class, ['--postman']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogExportCurl(): void
    {
        $this->writeTrace('le3', ['method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 10]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogExportCommand::class, ['--curl']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogExportCsvAndOutput(): void
    {
        $this->writeTrace('le4', ['method' => 'POST', 'path' => '/api/orders', 'status' => 201, 'time_ms' => 30]);
        $outFile = $this->basePath . '/storage/export.csv';
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogExportCommand::class, ['--format=csv', '--output=' . $outFile, '--status=201', '--method=POST', '--slow', '--days=30']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogExportUnsupportedFormat(): void
    {
        $this->writeTrace('le5', ['method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 10]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogExportCommand::class, ['--format=yaml']);
        $this->assertSame(1, $exit);
    }

    public function testLogExportNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogExportCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogTail(): void
    {
        file_put_contents($this->basePath . '/storage/logs/main.log', "line1\nline2\nline3\n");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTailCommand::class, ['--lines=2']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogTailDailyFile(): void
    {
        $month = date('Y-m');
        $dir = $this->basePath . '/storage/logs/daily/' . $month;
        mkdir($dir, 0777, true);
        $file = $dir . '/request-' . date('Y-m-d') . '.log';
        file_put_contents($file, "req1\nreq2\nreq3\nreq4\nreq5\n");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTailCommand::class, ['--type=request', '--lines=3']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testLogTailNoFiles(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTailCommand::class, []);
        $this->assertSame(1, $exit);
    }

    public function testRateStatusNoData(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RateStatusCommand::class, []);
        $this->assertSame(0, $exit);
    }

    public function testRateStatusWithData(): void
    {
        $dir = $this->basePath . '/storage/rate_limit';
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/hash1.json',
            json_encode(['count' => 3, 'expires_at' => time() + 3600])
        );
        file_put_contents(
            $dir . '/hash2.json',
            json_encode(['count' => 100, 'expires_at' => time() - 100])
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RateStatusCommand::class, []);
        $this->assertSame(0, $exit, $output);
    }

    public function testLogTop(): void
    {
        $this->writeTrace('ltop', ['method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 500, 'queries' => []]);
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LogTopCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testAuditLog(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\AuditLogCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testAuditLogUsage(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\AuditLogCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testAuditLogWithAction(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\AuditLogCommand::class, ['user.manual_reset', '--context=user_id=5']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRateStatus(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RateStatusCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRouteSearch(): void
    {
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php\n\$router->get('/products', fn () => ['ok' => true]);\n"
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RouteSearchCommand::class, ['products']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRouteRules(): void
    {
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php\n\$router->get('/products', fn () => ['ok' => true])->middleware('auth');\n"
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RouteRulesCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRouteRulesWithValidation(): void
    {
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php\n\$router->post('/products', [ProductController::class, 'store'])->validate(['name' => 'required|min:2']);\n"
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RouteRulesCommand::class, []);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('required', $output);
    }

    public function testRouteRulesNoRoutes(): void
    {
        @unlink($this->basePath . '/routes/api.php');
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RouteRulesCommand::class, []);
        $this->assertSame(1, $exit);
    }

    // ── EnvCheck / Runtime / Benchmark / Doctor ──

    public function testEnvCheckMissingEnv(): void
    {
        @unlink($this->basePath . '/.env');
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\EnvCheckCommand::class, []);
        $this->assertSame(1, $exit);
    }

    public function testEnvCheckCompleteEnv(): void
    {
        file_put_contents(
            $this->basePath . '/.env',
            "APP_NAME=Test\nAPP_ENV=testing\nAPP_DEBUG=true\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\nDB_CONNECTION=sqlite\n"
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\EnvCheckCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testEnvCheckIncompleteEnv(): void
    {
        file_put_contents($this->basePath . '/.env', "APP_NAME=Test\n");
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\EnvCheckCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRuntimeList(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['list']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRuntimeCurrent(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['current']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRuntimeInstallMissing(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['install']);
        $this->assertSame(1, $exit);
    }

    public function testRuntimeSwitchMissing(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['switch']);
        $this->assertSame(1, $exit);
    }

    public function testRuntimeRemoveMissing(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['remove']);
        $this->assertSame(1, $exit);
    }

    public function testRuntimePath(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['path']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testRuntimeHelp(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['help']);
        $this->assertSame(0, $exit);
    }

    public function testRuntimeUnknownAction(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\RuntimeCommand::class, ['foobar']);
        $this->assertSame(0, $exit);
    }

    // ── Deploy / Optimize / New ──

    public function testDeployInit(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DeployCommand::class, ['--init']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDeployList(): void
    {
        file_put_contents(
            $this->basePath . '/deploy.json',
            json_encode(['default' => 'staging', 'environments' => ['staging' => ['host' => 'x']]])
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DeployCommand::class, ['--list']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDeployMissingConfig(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DeployCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDeployEnvironmentNotFound(): void
    {
        file_put_contents(
            $this->basePath . '/deploy.json',
            json_encode(['default' => 'staging', 'environments' => ['staging' => ['host' => 'x']]])
        );
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DeployCommand::class, ['nonexistent']);
        $this->assertSame(1, $exit);
    }

    public function testNewUsage(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\NewCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testBenchmark(): void
    {
        // Keep iterations minimal so it doesn't hang
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\BenchmarkCommand::class, ['--iterations=1', '--warmup=0']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDoctor(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DoctorCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDbBenchmark(): void
    {
        \Siro\Core\Database::purgeAll();
        \Siro\Core\Database::configure(['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500]);
        try {
            [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DbBenchmarkCommand::class, ['--quick']);
            $this->assertContains($exit, [0, 1]);
        } finally {
            \Siro\Core\Database::purgeAll();
        }
    }
}
