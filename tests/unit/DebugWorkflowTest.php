<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\ApiWhyCommand;
use Siro\Core\Commands\DbWhyCommand;
use Siro\Core\Commands\DebugLastCommand;
use Siro\Core\Commands\FixCommand;
use Siro\Core\Commands\ReplayCommand;
use Siro\Core\Commands\TestCommand;
use Siro\Core\Commands\TestRegressionCommand;
use Siro\Core\Commands\TestRunCommand;

/**
 * End-to-end debug workflow coverage:
 * Why (debug:last / api:why / db:why) → Replay → Fix → Test → Regression.
 * Uses a local PHP built-in server for real request execution.
 */
final class DebugWorkflowTest extends TestCase
{
    private string $basePath;
    private string $tracesDir;
    private string $routerFile;
    private int $port = 18231;
    /** @var resource|null */
    private $serverProc = null;
    /** @var array<int, resource> */
    private array $serverPipes = [];
    /** @var array<int, string> */
    private array $fakeProjects = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_ENV=local');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_workflow_' . uniqid('', true);
        $this->tracesDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        mkdir($this->tracesDir, 0777, true);

        $this->routerFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_wf_router_' . uniqid('', true) . '.php';
        file_put_contents($this->routerFile, $this->routerScript());
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            $status = proc_get_status($this->serverProc);
            if ($status['running'] && function_exists('proc_terminate')) {
                proc_terminate($this->serverProc);
            }
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->serverProc);
            $this->serverProc = null;
            $this->serverPipes = [];
        }
        $this->restoreBootstrapEnv();
        \Siro\Core\Cache::reset();
        \Siro\Core\Logger::reset();
        \Siro\Core\Database::purgeAll();
        foreach ($this->fakeProjects as $dir) {
            if (is_dir($dir)) {
                $this->rmDir($dir);
            }
        }
        $this->fakeProjects = [];
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        @unlink($this->routerFile);
        parent::tearDown();
    }

    private function restoreBootstrapEnv(): void
    {
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $_ENV['APP_KEY'] = 'test_app_key_for_encryption_tests_32chars!!';
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
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

    private function routerScript(): string
    {
        return <<<'PHP'
<?php
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'];
if (str_starts_with($uri, '/health')) {
    echo json_encode(['success' => true, 'status' => 'ok']);
    return;
}
if ($uri === '/api/orders') {
    echo json_encode(['success' => true, 'id' => 123, 'total' => 100]);
    return;
}
if (str_starts_with($uri, '/api/products')) {
    echo json_encode(['success' => true, 'items' => [['id' => 1, 'name' => 'Widget']]]);
    return;
}
if ($uri === '/plain') {
    echo 'plain text response';
    return;
}
http_response_code(404);
echo json_encode(['success' => false, 'error' => 'not found']);
PHP;
    }

    private function startServer(): void
    {
        if ($this->serverProc !== null) {
            return;
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $this->serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->routerFile], $descriptors, $pipes);
        $this->serverPipes = $pipes;
        $ok = false;
        for ($i = 0; $i < 20; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                $ok = true;
                break;
            }
        }
        $this->assertTrue($ok, 'PHP built-in server did not start');
    }

    private function writeTrace(string $name, array $data): void
    {
        file_put_contents(
            $this->tracesDir . DIRECTORY_SEPARATOR . $name . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
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

    // ── Why: DebugLastCommand ──

    public function testWhyNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(DebugLastCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No traces found', $output);
    }

    public function testWhyShowsTraceDetails(): void
    {
        $this->writeTrace('w1', [
            'method' => 'POST', 'path' => '/api/orders', 'status' => 500,
            'time_ms' => 250, 'middleware' => [
                ['name' => 'auth', 'passed' => true, 'time_ms' => 5],
                ['name' => 'throttle', 'passed' => false, 'time_ms' => 150],
            ],
            'queries' => [
                ['sql' => 'SELECT * FROM orders', 'time_ms' => 120],
            ],
            'exception' => ['class' => 'RuntimeException', 'message' => 'no such table: orders'],
            'response_body' => '{"success":false}',
        ]);
        [$exit, $output] = $this->runCmd(DebugLastCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('POST /api/orders', $output);
        $this->assertStringContainsString('no such table', $output);
        $this->assertStringContainsString('Possible Cause', $output);
        $this->assertStringContainsString('Suggested Fix', $output);
        $this->assertStringContainsString('Middleware Pipeline', $output);
        $this->assertStringContainsString('SQL Queries', $output);
        $this->assertStringContainsString('Replay', $output);
    }

    public function testWhyExceptionStringForm(): void
    {
        $this->writeTrace('w2', [
            'method' => 'GET', 'path' => '/api/products', 'status' => 401,
            'time_ms' => 10, 'exception' => 'Unauthorized',
            'response_body' => '{}',
        ]);
        [$exit, $output] = $this->runCmd(DebugLastCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Unauthorized', $output);
        $this->assertStringContainsString('401', $output);
    }

    public function testWhyN1Detection(): void
    {
        if (!class_exists(\Siro\Core\Model::class)) {
            $this->markTestSkipped('Model not available');
        }
        \Siro\Core\Model::clearIdentityMap();
        $this->writeTrace('w3', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'time_ms' => 5, 'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(DebugLastCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replay', $output);
    }

    // ── Why: ApiWhyCommand ──

    public function testApiWhyUsage(): void
    {
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testApiWhyInvalidMethod(): void
    {
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['FOO', '/x']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid method', $output);
    }

    public function testApiWhyNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['GET', '/api/orders']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No traces found', $output);
    }

    public function testApiWhyNoMatchingTrace(): void
    {
        $this->writeTrace('w4', ['method' => 'GET', 'path' => '/health', 'status' => 200]);
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['GET', '/api/orders']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No trace found', $output);
    }

    public function testApiWhyMatch(): void
    {
        $this->writeTrace('w5', [
            'method' => 'POST', 'path' => '/api/orders', 'status' => 422,
            'time_ms' => 20, 'response_body' => '{"success":false}',
            'exception' => ['class' => 'ValidationException', 'message' => 'The price field is required'],
        ]);
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['POST', '/api/orders']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('POST /api/orders', $output);
        $this->assertStringContainsString('422', $output);
        $this->assertStringContainsString('Possible Cause', $output);
    }

    public function testApiWhyNestedTrace(): void
    {
        // trace with middleware + response source
        $this->writeTrace('w6', [
            'method' => 'GET', 'path' => '/api/products', 'status' => 200,
            'time_ms' => 30,
            'middleware' => [['name' => 'json', 'passed' => true, 'time_ms' => 1]],
            'response_body' => '{"_source":"cache","success":true}',
        ]);
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['GET', '/api/products']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('cache', $output);
    }

    public function testApiWhySqlQueriesDisplay(): void
    {
        $this->writeTrace('wqueries', [
            'method' => 'GET', 'path' => '/api/orders', 'status' => 200,
            'time_ms' => 30,
            'queries' => [
                ['sql' => 'SELECT * FROM orders WHERE id = ?', 'time_ms' => 5],
                ['sql' => 'SELECT * FROM orders_items WHERE order_id = ?', 'time_ms' => 150],
            ],
            'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['GET', '/api/orders']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SQL Queries', $output);
        $this->assertStringContainsString('SELECT', $output);
        $this->assertStringContainsString('Total SQL', $output);
    }

    public function testApiWhyStringException(): void
    {
        $this->writeTrace('wex', [
            'method' => 'POST', 'path' => '/api/orders', 'status' => 500,
            'time_ms' => 10,
            'exception' => 'RuntimeException: something exploded',
            'response_body' => '{"success":false}',
        ]);
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['POST', '/api/orders']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('something exploded', $output);
        $this->assertStringContainsString('Possible Cause', $output);
        $this->assertStringContainsString('Suggested Fix', $output);
    }

    public function testApiWhyInvalidTraceFileSkipped(): void
    {
        file_put_contents($this->tracesDir . DIRECTORY_SEPARATOR . 'badtrace.json', '{ bad');
        $this->writeTrace('wok', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'time_ms' => 5, 'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(ApiWhyCommand::class, ['GET', '/health']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('GET /health', $output);
    }

    // ── Why: DbWhyCommand ──

    public function testDbWhyNoArgs(): void
    {
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDbWhyWithTraceArg(): void
    {
        $sql1 = 'SELECT * FROM products WHERE id = ?';
        $sql2 = 'SELECT * FROM orders WHERE user_id = ?';
        $this->writeTrace('w7', [
            'method' => 'GET', 'path' => '/api/products', 'status' => 200,
            'time_ms' => 60,
            'queries' => [
                ['sql' => $sql1, 'time_ms' => 30],
                ['sql' => $sql2, 'time_ms' => 200],
            ],
            'response_body' => '{"success":true}',
        ]);
        $hash = substr(sha1($sql2), 0, 8);
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, [$hash]);
        $this->assertSame(0, $exit, 'output: ' . $output);
        $this->assertStringContainsString('SELECT', $output);
    }

    public function testDbWhyQueryDirect(): void
    {
        // --query as the first arg → queryHash=args[0] non-empty, so line 42 skipped.
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, ['--query=SELECT * FROM users']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SELECT', $output);
    }

    public function testDbWhyQueryDirectHashComputation(): void
    {
        // Empty first arg + --query → line 42 computes the hash.
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, ['', '--query=SELECT * FROM users']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SELECT', $output);
    }

    public function testDbWhySlow(): void
    {
        $this->writeTrace('w8', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'time_ms' => 5,
            'queries' => [['sql' => 'SELECT 1', 'time_ms' => 200]],
            'response_body' => '{}',
        ]);
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, ['--slow']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SELECT 1', $output);
    }

    public function testDbWhyNoTrace(): void
    {
        // Random hash won't match any trace's query → exit 1
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, ['00000000']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No traces', $output);
    }

    // ── Replay: ReplayCommand ──

    public function testReplayNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(ReplayCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No traces found', $output);
    }

    public function testReplayLastTraceDelegates(): void
    {
        $this->startServer();
        $this->writeTrace('replay1', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => '',
            'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(ReplayCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying last trace', $output);
        $this->assertStringContainsString('Replaying GET', $output);
    }

    public function testReplayWithExplicitTraceId(): void
    {
        $this->startServer();
        $this->writeTrace('replay2', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmd(ReplayCommand::class, ['replay2', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying GET', $output);
    }

    // ── Fix: FixCommand ──

    public function testFixWithTraceReplay(): void
    {
        $this->startServer();
        $this->writeTrace('fix1', [
            'method' => 'POST', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"key":"value"}',
            'request_headers' => ['X-Custom' => 'hdr', 'Host' => 'ignored', 'Content-Type' => 'application/json'],
            'auth_header' => 'Bearer tok',
            'content_type' => 'application/json',
            'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(FixCommand::class, ['fix1']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Fix replay', $output);
        $this->assertStringContainsString('Status:', $output);
    }

    public function testFixNonJsonResponse(): void
    {
        $this->startServer();
        $this->writeTrace('fixplain', [
            'method' => 'GET', 'path' => '/plain', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port, 'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => 'some plain text',
        ]);
        [$exit, $output] = $this->runCmd(FixCommand::class, ['fixplain']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Fix replay', $output);
    }

    public function testFixTraceNotFound(): void
    {
        [$exit, $output] = $this->runCmd(FixCommand::class, ['nonexistent']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Trace not found', $output);
    }

    public function testFixInvalidHostRejected(): void
    {
        $this->writeTrace('fixevil', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => 'evil.com\@x', 'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmd(FixCommand::class, ['fixevil']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to replay', $output);
    }

    public function testFixNoApiTestHistory(): void
    {
        [$exit, $output] = $this->runCmd(FixCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No previous api:test', $output);
    }

    public function testFixReconstructsFromHistory(): void
    {
        $historyDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0777, true);
        }
        file_put_contents(
            $historyDir . DIRECTORY_SEPARATOR . 'api-test-history.json',
            json_encode([['method' => 'GET', 'path' => '/api/products', 'fields' => ['page' => 1]]])
        );
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('getLastApiTest');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        $result = $m->invoke($cmd);
        $this->assertStringContainsString('api:test GET /api/products', $result);
    }

    // ── Test: TestCommand ──

    public function testTestCommandRunsPhpunit(): void
    {
        $projDir = $this->makeFakeProjectWithPhpunit();
        [$exit, $output] = $this->runCmdWithBase(TestCommand::class, ['--filter=FooTest'], $projDir);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Running', $output);
        $this->assertStringContainsString('All tests passed', $output);
    }

    public function testTestCommandMissingPhpunit(): void
    {
        [$exit, $output] = $this->runCmd(TestCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('PHPUnit not found', $output);
    }

    public function testTestCommandCoverageFlag(): void
    {
        $projDir = $this->makeFakeProjectWithPhpunit();
        [$exit, $output] = $this->runCmdWithBase(TestCommand::class, ['--coverage', '--filter=FooTest'], $projDir);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('coverage', $output);
    }

    public function testTestCommandSuiteFlag(): void
    {
        $projDir = $this->makeFakeProjectWithPhpunit();
        [$exit, $output] = $this->runCmdWithBase(TestCommand::class, ['--suite=Unit'], $projDir);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--testsuite', $output);
    }

    public function testTestCommandFailurePath(): void
    {
        $projDir = $this->makeFakeProjectWithPhpunit(1);
        [$exit, $output] = $this->runCmdWithBase(TestCommand::class, [], $projDir);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Some tests failed', $output);
    }

    // ── Test: TestRunCommand ──

    public function testTestRunCommand(): void
    {
        $projDir = $this->makeFakeProjectWithPhpunit();
        $testsDir = $projDir . DIRECTORY_SEPARATOR . 'tests';
        mkdir($testsDir, 0777, true);
        [$exit, $output] = $this->runCmdWithBase(TestRunCommand::class, ['--filter=FooTest', '-v'], $projDir);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('PHPUnit Test Suite', $output);
        $this->assertStringContainsString('Done in', $output);
    }

    public function testTestRunCommandFailure(): void
    {
        $projDir = $this->makeFakeProjectWithPhpunit(1);
        $testsDir = $projDir . DIRECTORY_SEPARATOR . 'tests';
        mkdir($testsDir, 0777, true);
        [$exit, $output] = $this->runCmdWithBase(TestRunCommand::class, ['--testsuite=Unit', 'somefile.php'], $projDir);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Done in', $output);
    }

    public function testTestRunNoTestsDir(): void
    {
        [$exit, $output] = $this->runCmd(TestRunCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No tests directory', $output);
    }

    public function testTestRunPhpunitMissing(): void
    {
        $projDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_no_phpunit_' . uniqid('', true);
        $this->fakeProjects[] = $projDir;
        mkdir($projDir . DIRECTORY_SEPARATOR . 'tests', 0777, true);
        [$exit, $output] = $this->runCmdWithBase(TestRunCommand::class, [], $projDir);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('PHPUnit not found', $output);
    }

    /**
     * Build a temp project with a fake vendor/bin/phpunit that echoes args
     * and exits 0, so the Test/TestRun commands can be exercised quickly.
     */
    private function makeFakeProjectWithPhpunit(int $exitCode = 0): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_fake_proj_' . uniqid('', true);
        $this->fakeProjects[] = $dir;
        $vendorBin = $dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';
        mkdir($vendorBin, 0777, true);
        $shim = "#!/usr/bin/env php\n<?php\necho \"PHPUnit (shim) ran with args: \" . implode(' ', array_slice(\$argv, 1)) . \"\\n\";\nexit($exitCode);\n";
        file_put_contents($vendorBin . DIRECTORY_SEPARATOR . 'phpunit', $shim);
        // TestRunCommand invokes the file directly (no `php` prefix) — needs a .bat on Windows
        $bat = "@echo off\r\nphp \"%~dp0phpunit\" %*\r\nexit /b $exitCode\r\n";
        file_put_contents($vendorBin . DIRECTORY_SEPARATOR . 'phpunit.bat', $bat);
        return $dir;
    }

    // ── Regression: TestRegressionCommand ──

    public function testRegressionNoTraces(): void
    {
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No traces found', $output);
    }

    public function testRegressionAllPass(): void
    {
        $this->startServer();
        $this->writeTrace('reg1', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => '{"success":true,"status":"ok"}',
            'trace_id' => 'reg1',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no regressions', $output);
    }

    public function testRegressionDetectsChange(): void
    {
        $this->startServer();
        // Trace says 404 but server returns 200 → status_changed detected
        $this->writeTrace('reg2', [
            'method' => 'GET', 'path' => '/health', 'status' => 404,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => '{"success":false}',
            'trace_id' => 'reg2',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, ['--status=404']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('status_changed', $output);
    }

    public function testRegressionLimit(): void
    {
        $this->startServer();
        for ($i = 0; $i < 3; $i++) {
            $this->writeTrace("regl$i", [
                'method' => 'GET', 'path' => '/health', 'status' => 200,
                'host' => '127.0.0.1:' . $this->port,
                'request_body' => '', 'request_headers' => [],
                'auth_header' => '', 'response_body' => '{"success":true}',
            ]);
            usleep(1100000);
        }
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, ['--limit=2']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying', $output);
        $this->assertStringContainsString('2', $output);
    }

    public function testRegressionInvalidTraceSkipped(): void
    {
        $this->startServer();
        file_put_contents($this->tracesDir . DIRECTORY_SEPARATOR . 'bad.json', '{ bad');
        $this->writeTrace('regok', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no regressions', $output);
    }

    public function testRegressionMissingKeyDetected(): void
    {
        $this->startServer();
        // Original response has a key the server no longer returns → missing_key detected
        $this->writeTrace('regmissing', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => '{"success":true,"extra":"field"}',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, ['--fail']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing_key', $output);
    }

    public function testRegressionWithBodyAndAuth(): void
    {
        $this->startServer();
        $this->writeTrace('regbody', [
            'method' => 'POST', 'path' => '/api/orders', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"Widget"}',
            'request_headers' => ['X-Custom' => 'hdr'],
            'auth_header' => 'Bearer tok',
            'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no regressions', $output);
    }

    public function testRegressionStatusFilterSkipsMismatches(): void
    {
        $this->startServer();
        // Two traces: one 200, one 404. Filter --status=404 must skip the 200 one.
        $this->writeTrace('regf200', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => '{"success":true}',
        ]);
        usleep(1100000);
        $this->writeTrace('regf404', [
            'method' => 'GET', 'path' => '/api/products', 'status' => 404,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '', 'response_body' => '{"success":false}',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, ['--status=404']);
        // Only the 404 trace is replayed (Total: 1); the 200 trace is skipped by the filter
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Total:', $output);
        $this->assertStringContainsString('status_changed', $output);
    }

    public function testRegressionContentLengthHeaderSkipped(): void
    {
        $this->startServer();
        $this->writeTrace('regcl', [
            'method' => 'POST', 'path' => '/api/orders', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"a":1}',
            'request_headers' => ['Content-Length' => '7', 'Host' => 'x', 'X-Test' => 'v'],
            'auth_header' => '',
            'response_body' => '{"success":true}',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, []);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no regressions', $output);
    }

    public function testRegressionConnectionErrorCounts(): void
    {
        // Trace points to an unreachable host → curl error → errors counter
        $this->writeTrace('regerr', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:1',
            'request_body' => '', 'request_headers' => [],
            'auth_header' => '',
            'response_body' => '{"success":true}',
            'trace_id' => 'regerr',
        ]);
        [$exit, $output] = $this->runCmd(TestRegressionCommand::class, ['--fail']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Errors:', $output);
        $this->assertStringContainsString('connection_error', $output);
    }

    /**
     * @param class-string $class
     * @param array<int, string> $args
     * @return array{int, string}
     */
    private function runCmdWithBase(string $class, array $args, string $basePath): array
    {
        ob_start();
        /** @var object $cmd */
        $cmd = new $class($basePath);
        $exit = $cmd->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }
}
