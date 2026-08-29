<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\LogReplayCommand;

/**
 * LogReplayCommand execution paths against a local PHP built-in server.
 * Covers real curl replay, diff mode, production safety, --set overrides,
 * and the --test delegation.
 */
final class LogReplayExecutionTest extends TestCase
{
    private string $basePath;
    private string $tracesDir;
    private string $routerFile;
    private int $port = 18123;
    /** @var resource|null */
    private $serverProc = null;
    /** @var array<int, resource> */
    private array $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_ENV=local');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_replay_exec_' . uniqid('', true);
        $this->tracesDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        mkdir($this->tracesDir, 0777, true);

        $this->routerFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_replay_router_' . uniqid('', true) . '.php';
        file_put_contents($this->routerFile, $this->routerScript());
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            $status = proc_get_status($this->serverProc);
            if ($status['running']) {
                if (function_exists('proc_terminate')) {
                    proc_terminate($this->serverProc);
                }
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
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        @unlink($this->routerFile);
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

    private function routerScript(): string
    {
        return <<<'PHP'
<?php
header('Content-Type: application/json');
if (str_starts_with($_SERVER['REQUEST_URI'], '/health')) {
    echo json_encode(['status' => 'ok', 'method' => $_SERVER['REQUEST_METHOD']]);
    return;
}
if ($_SERVER['REQUEST_URI'] === '/login' || $_SERVER['REQUEST_URI'] === '/api/auth/login') {
    echo json_encode(['data' => ['token' => 'fresh-login-token']]);
    return;
}
if ($_SERVER['REQUEST_URI'] === '/refresh' || $_SERVER['REQUEST_URI'] === '/api/auth/refresh') {
    echo json_encode(['data' => ['token' => 'fresh-refresh-token']]);
    return;
}
if ($_SERVER['REQUEST_URI'] === '/register' || $_SERVER['REQUEST_URI'] === '/api/auth/register') {
    echo json_encode(['data' => ['token' => 'fresh-register-token']]);
    return;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/echo')) {
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $_SERVER['REQUEST_URI'],
        'body' => file_get_contents('php://input'),
        'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    ]);
    return;
}
if ($_SERVER['REQUEST_URI'] === '/plain') {
    echo 'not json at all';
    return;
}
http_response_code(404);
echo json_encode(['error' => 'not found']);
PHP;
    }

    private function startServer(): void
    {
        if ($this->serverProc !== null) {
            return;
        }
        // Random port to avoid conflicts with orphaned servers from prior runs.
        $port = random_int(19100, 19999);
        $this->port = $port;
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->routerFile];
        $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']];
        $env = array_merge(getenv(), ['XDEBUG_MODE' => 'off']);
        $pipes = [];
        $this->serverProc = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->serverPipes = $pipes;
        // Give the server time to bind
        $ok = false;
        for ($i = 0; $i < 80; $i++) {
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

    private function writeTrace(string $name, array $data): string
    {
        $path = $this->tracesDir . DIRECTORY_SEPARATOR . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $name;
    }

    /** @param array<int, string> $args @return array{int, string} */
    private function runCmd(array $args): array
    {
        ob_start();
        $cmd = new LogReplayCommand($this->basePath);
        $exit = $cmd->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }

    public function testGetReplayExecutesAgainstServer(): void
    {
        $this->startServer();
        $this->writeTrace('gettrace', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => '',
            'response_body' => '{"status":"ok"}',
        ]);
        [$exit, $output] = $this->runCmd(['gettrace', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying GET', $output);
        $this->assertStringContainsString('"ok"', $output);
    }

    public function testPostReplayExecutesWithBody(): void
    {
        $this->startServer();
        $this->writeTrace('posttrace', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"Widget"}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        [$exit, $output] = $this->runCmd(['posttrace', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying POST', $output);
        $this->assertStringContainsString('Widget', $output);
    }

    public function testDiffModeComparesResponses(): void
    {
        $this->startServer();
        $this->writeTrace('difftrace', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => '',
            'response_body' => '{"status":"ok"}',
        ]);
        [$exit, $output] = $this->runCmd(['difftrace', '--diff']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('BEFORE', $output);
        $this->assertStringContainsString('AFTER', $output);
    }

    public function testProductionAutoDryRun(): void
    {
        putenv('APP_ENV=production');
        $this->writeTrace('prodtrace', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => 'localhost:8080', 'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmd(['prodtrace']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Production environment detected', $output);
        putenv('APP_ENV=local');
    }

    public function testSetOverrideModifiesBody(): void
    {
        $this->startServer();
        $this->writeTrace('settrace', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"Old","price":5}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        [$exit, $output] = $this->runCmd(['settrace', '--force', '--set=name=New', '--set=price=10']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('New', $output);
    }

    public function testSetDotNotation(): void
    {
        $this->startServer();
        $this->writeTrace('dottrace', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"items":[{"name":"A"}]}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        [$exit, $output] = $this->runCmd(['dottrace', '--force', '--set=items.0.name=B']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('B', $output);
    }

    public function testTestDelegationCreatesTestFile(): void
    {
        $this->writeTrace('testtrace', [
            'method' => 'GET', 'path' => '/api/users', 'status' => 200,
            'host' => 'localhost:8080', 'request_body' => '',
            'response_body' => '{"id":1}',
        ]);
        [$exit, $output] = $this->runCmd(['testtrace', '--test']);
        // make:test --from-trace should generate a test file
        $this->assertSame(0, $exit);
        $files = glob($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . '*.php');
        $this->assertNotEmpty($files, 'make:test should generate a test file');
    }

    public function testHttpieOutputFormat(): void
    {
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('outputHttpie');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, 'POST', 'http://localhost:8080/api/x', '{"a":1}', ['X-Test' => 'v'], 'Bearer abc');
        $output = ob_get_clean() ?: '';
        $this->assertStringContainsString('http', $output);
        $this->assertStringContainsString('http://localhost:8080/api/x', $output);
        $this->assertStringContainsString('X-Test:v', $output);
    }

    public function testOutputCurlFromWriteMethodWithoutForce(): void
    {
        $this->writeTrace('writetrace', [
            'method' => 'DELETE', 'path' => '/api/users/5', 'status' => 204,
            'host' => 'localhost:8080', 'request_body' => '',
        ]);
        // DELETE without --force should emit curl command and return 0
        [$exit, $output] = $this->runCmd(['writetrace']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('curl', $output);
        $this->assertStringContainsString('--force', $output);
    }

    public function testAuditLogWritten(): void
    {
        $logsDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }
        $this->startServer();
        $this->writeTrace('audittrace', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port, 'request_body' => '',
        ]);
        $this->runCmd(['audittrace', '--force']);
        $auditFile = $logsDir . DIRECTORY_SEPARATOR . 'replay-audit.log';
        $this->assertFileExists($auditFile);
        $content = (string) file_get_contents($auditFile);
        $this->assertStringContainsString('audittrace', $content);
    }

    public function testIsValidHostEdgeCases(): void
    {
        $this->assertFalse(LogReplayCommand::isValidHost('host:0'));
        $this->assertFalse(LogReplayCommand::isValidHost('host:70000'));
        $this->assertFalse(LogReplayCommand::isValidHost('host:abc'));
        $this->assertTrue(LogReplayCommand::isValidHost('[::1]:8080'));
        $this->assertTrue(LogReplayCommand::isValidHost('[::1]'));
        $this->assertFalse(LogReplayCommand::isValidHost('[not-ipv6]'));
        $this->assertTrue(LogReplayCommand::isValidHost('127.0.0.1'));
    }

    public function testTraceWithMissingPathRejected(): void
    {
        $this->writeTrace('empty', ['method' => 'GET', 'host' => 'localhost', 'path' => '']);
        [$exit, $output] = $this->runCmd(['empty']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing required field: path', $output);
    }

    public function testAuthModeRefreshesTokenFromStoredFile(): void
    {
        $this->startServer();
        // Store an auth file with a refresh token
        $authFile = $this->basePath . DIRECTORY_SEPARATOR . '.siro_auth.json';
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $write = $ref->getMethod('writeAuthFile');
        $write->setAccessible(true);
        putenv('APP_KEY=');
        $write->invoke($cmd, $authFile, [
            'email' => 'a@b.com',
            'refresh_token' => 'rt-secret',
            'access_token' => 'at-secret',
        ]);
        $this->restoreBootstrapEnv();

        $this->writeTrace('authtrace', [
            'method' => 'GET', 'path' => '/health', 'status' => 401,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => 'Bearer old-token',
            'response_body' => '{"message":"unauthorized"}',
        ]);
        [$exit, $output] = $this->runCmd(['authtrace', '--auth', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('refreshed', $output);
    }

    private function restoreBootstrapEnv(): void
    {
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $_ENV['APP_KEY'] = 'test_app_key_for_encryption_tests_32chars!!';
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
    }

    public function testExtractTokenViaReflection(): void
    {
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('extractToken');
        $m->setAccessible(true);

        $resp = ['data' => ['token' => 't1']];
        $this->assertSame('t1', $m->invoke($cmd, $resp, 'data.token'));
        $this->assertSame('t1', $m->invoke($cmd, $resp, 'access_token')); // fallback
        $this->assertNull($m->invoke($cmd, [], 'data.token'));
    }

    public function testOutputCurlViaReflection(): void
    {
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('outputCurl');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, 'POST', 'http://localhost:8080/api/x', '{"a":1}', ['X-Test' => 'v', 'Host' => 'x'], 'Bearer tok', []);
        $output = ob_get_clean() ?: '';
        $this->assertStringContainsString('curl -X POST', $output);
        $this->assertStringContainsString('-d', $output);
        $this->assertStringContainsString('Authorization', $output);
    }

    public function testPrettyPrintViaReflection(): void
    {
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('prettyPrint');
        $m->setAccessible(true);
        $pretty = $m->invoke($cmd, '{"a":1}');
        $this->assertStringContainsString("\n", $pretty);
        $plain = $m->invoke($cmd, 'not json');
        $this->assertSame('not json', $plain);
    }

    public function testDiscoverAuthConfigDefaults(): void
    {
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('discoverAuthConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke($cmd);
        $this->assertSame('/api/auth/login', $cfg['endpoint']);
        $this->assertSame('data.token', $cfg['token_path']);
    }

    public function testAuthConfigReadsEnvOverrides(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "AUTH_ENDPOINT=/api/login\nAUTH_EMAIL_FIELD=username\nAUTH_PASSWORD_FIELD=pass\nAUTH_TOKEN_PATH=token\n"
        );
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('authConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke($cmd);
        $this->assertSame('/api/login', $cfg['endpoint']);
        $this->assertSame('username', $cfg['email_field']);
        $this->assertSame('pass', $cfg['pass_field']);
        $this->assertSame('token', $cfg['token_path']);
    }

    public function testRefreshTokenViaReflection(): void
    {
        $this->startServer();
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('refreshToken');
        $m->setAccessible(true);
        $token = $m->invoke($cmd, '127.0.0.1:' . $this->port, 'rt-secret');
        $this->assertSame('fresh-refresh-token', $token);
    }

    public function testLoginViaReflection(): void
    {
        $this->startServer();
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('login');
        $m->setAccessible(true);
        $token = $m->invoke($cmd, '127.0.0.1:' . $this->port, 'a@b.com', 'pw');
        $this->assertSame('fresh-login-token', $token);
    }

    public function testExecuteReplayWithUnreachableHost(): void
    {
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('executeReplay');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, 'GET', 'http://127.0.0.1:1/none', '', [], '', '', [], false);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testAutoReauthenticateWithEnvAdmin(): void
    {
        $this->startServer();
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "ADMIN_EMAIL=admin@test.com\nADMIN_PASSWORD=adminpass\n"
        );
        putenv('APP_ENV=local');
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('autoReauthenticate');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, '127.0.0.1:' . $this->port, ['auth_header' => 'Bearer x']);
        $this->assertNotNull($result['token']);
        $this->assertSame('env_admin', $result['strategy']);
    }

    public function testAutoReauthenticateNoAuth(): void
    {
        // Production env → skips register; no credentials → strategy no_auth
        putenv('APP_ENV=production');
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('autoReauthenticate');
        $m->setAccessible(true);
        ob_start();
        $result = $m->invoke($cmd, '127.0.0.1:1', []);
        ob_end_clean();
        $this->assertNull($result['token']);
        $this->assertSame('no_auth', $result['strategy']);
    }

    public function testLoginDevOnlyViaReflection(): void
    {
        $this->startServer();
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('loginDevOnly');
        $m->setAccessible(true);
        $token = $m->invoke($cmd, '127.0.0.1:' . $this->port, 'a@b.com', 'pw');
        $this->assertSame('fresh-login-token', $token);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . '.siro_auth.json');
    }

    public function testDiscoverAuthConfigFromModelAndRoutes(): void
    {
        // Create a routes file that triggers the regex match
        $routesDir = $this->basePath . DIRECTORY_SEPARATOR . 'routes';
        mkdir($routesDir, 0777, true);
        file_put_contents(
            $routesDir . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\n\$router->post('/api/custom/login', [AuthController::class, 'login']);\n"
        );
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('discoverAuthConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke($cmd);
        $this->assertSame('/api/custom/login', $cfg['endpoint']);
        $this->assertSame('/api/custom/refresh', $cfg['refresh_endpoint']);
    }

    public function testDiscoverAuthConfigFromMigration(): void
    {
        $migDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        mkdir($migDir, 0777, true);
        file_put_contents(
            $migDir . DIRECTORY_SEPARATOR . '001_users.php',
            "<?php\nSchema::create('users', function (\$t) {\n    \$t->string('username');\n});\n"
        );
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('discoverAuthConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke($cmd);
        $this->assertSame('username', $cfg['email_field']);
    }

    public function testFormatHttpieOutputsHttpieCommand(): void
    {
        $this->writeTrace('httpiertrace', [
            'method' => 'DELETE', 'path' => '/api/users/9', 'status' => 204,
            'host' => 'localhost:8080', 'request_body' => '{"reason":"x"}',
        ]);
        // DELETE without --force emits a preview; --format=httpie must use httpie syntax
        [$exit, $output] = $this->runCmd(['httpiertrace', '--format=httpie']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('http delete http://localhost:8080/api/users/9', $output);
        $this->assertStringNotContainsString('curl -X', $output);
    }

    public function testFormatCurlDefault(): void
    {
        $this->writeTrace('curltrace', [
            'method' => 'DELETE', 'path' => '/api/users/9', 'status' => 204,
            'host' => 'localhost:8080', 'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmd(['curltrace']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('curl -X DELETE', $output);
    }

    /**
     * Run the command in a child process with piped stdin so interactive
     * prompts (--edit, --as) can be answered.
     *
     * @param array<int, string> $args
     * @return array{int, string, string} [exit, stdout, stderr]
     */
    private function runCmdWithInput(array $args, string $stdin): array
    {
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_replay_child_' . uniqid('', true) . '.php';
        $runner = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
putenv('APP_ENV=local');
$cmd = new \Siro\Core\Commands\LogReplayCommand($argv[2]);
$args = array_slice($argv, 3);
exit($cmd->run($args));
PHP;
        file_put_contents($script, $runner);
        $proc = proc_open(
            [PHP_BINARY, $script, dirname(__DIR__, 2), $this->basePath, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($script);
        return [$exitCode, (string) $stdout, (string) $stderr];
    }

    public function testEditModeUpdatesBodyViaInput(): void
    {
        $this->startServer();
        $this->writeTrace('edittrace', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"Old","price":5}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        // editRecursive asks for each field; "New" for name, empty for price (keep)
        [$exit, $output, $stderr] = $this->runCmdWithInput(['edittrace', '--edit'], "New\n\n");
        $this->assertSame(0, $exit, 'stderr: ' . $stderr);
        $this->assertStringContainsString('Edit request body', $output);
        $this->assertStringContainsString('New', $output);
    }

    public function testEditModeEmptyBody(): void
    {
        $this->startServer();
        $this->writeTrace('editempty', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        [$exit, $output, $stderr] = $this->runCmdWithInput(['editempty', '--edit'], "\n");
        $this->assertSame(0, $exit, 'stderr: ' . $stderr);
        $this->assertStringContainsString('empty body', $output);
    }

    public function testEditModeRawBody(): void
    {
        $this->startServer();
        $this->writeTrace('editraw', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => 'plain text body',
            'request_headers' => ['Content-Type' => 'text/plain'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        [$exit, $output, $stderr] = $this->runCmdWithInput(['editraw', '--edit'], "edited\n");
        $this->assertSame(0, $exit, 'stderr: ' . $stderr);
        $this->assertStringContainsString('raw body', $output);
    }

    public function testAsUserLoginFlow(): void
    {
        $this->startServer();
        $this->writeTrace('astrace', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => '',
            'response_body' => '{"status":"ok"}',
        ]);
        // --as prompts for password, then logs in against the local server
        [$exit, $output, $stderr] = $this->runCmdWithInput(['astrace', '--as=admin@test.com', '--force'], "secret123\n");
        $this->assertSame(0, $exit, 'stderr: ' . $stderr);
        $this->assertStringContainsString('logged in as admin@test.com', $output);
    }

    public function testAsUserEmptyPasswordRejected(): void
    {
        $this->startServer();
        $this->writeTrace('asempty', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '',
        ]);
        // Empty password → rejected
        [$exit, $output, $stderr] = $this->runCmdWithInput(['asempty', '--as=admin@test.com'], "\n");
        $this->assertSame(1, $exit, 'stderr: ' . $stderr);
        $this->assertStringContainsString('Password required', $output);
    }

    public function testProductionForceRequiresConfirmation(): void
    {
        $this->startServer();
        $this->writeTrace('prodf', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '',
        ]);
        // Run child with APP_ENV=production and answer "yes"
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_replay_child_' . uniqid('', true) . '.php';
        $runner = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
putenv('APP_ENV=production');
$cmd = new \Siro\Core\Commands\LogReplayCommand($argv[2]);
$args = array_slice($argv, 3);
exit($cmd->run($args));
PHP;
        file_put_contents($script, $runner);
        $proc = proc_open(
            [PHP_BINARY, $script, dirname(__DIR__, 2), $this->basePath, 'prodf', '--force'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fwrite($pipes[0], "yes\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($script);
        $this->assertSame(0, $exitCode, 'stderr: ' . $stderr);
        $this->assertStringContainsString('DANGER', (string) $stdout);
        $this->assertStringContainsString('Replaying', (string) $stdout);
    }

    public function testProductionConfirmationDeclined(): void
    {
        $this->startServer();
        $this->writeTrace('prodn', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '',
        ]);
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_replay_child_' . uniqid('', true) . '.php';
        $runner = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
putenv('APP_ENV=production');
$cmd = new \Siro\Core\Commands\LogReplayCommand($argv[2]);
$args = array_slice($argv, 3);
exit($cmd->run($args));
PHP;
        file_put_contents($script, $runner);
        $proc = proc_open(
            [PHP_BINARY, $script, dirname(__DIR__, 2), $this->basePath, 'prodn', '--force'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fwrite($pipes[0], "no\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($script);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cancelled', (string) $stdout);
    }

    public function testEditModeInProcessCoversPath(): void
    {
        // In-process run: empty stdin keeps values (readline returns empty on non-tty).
        // The run proceeds through the full --edit execution path.
        $this->writeTrace('editproc', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"Old","price":5}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        [$exit, $output] = $this->runCmdWithInput(['editproc', '--edit'], "\n");
        $this->assertStringContainsString('Edit request body', $output);
        $this->assertStringContainsString('Updated body', $output);
        $this->assertStringContainsString('Replaying POST', $output);
    }

    public function testAsUserEmptyPasswordInProcess(): void
    {
        $this->writeTrace('asempty2', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmdWithInput(['asempty2', '--as=admin@test.com'], "\n");
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Password required', $output);
    }

    public function testAuthModeNoStoredFileFallsThrough(): void
    {
        $this->startServer();
        $this->writeTrace('authnf', [
            'method' => 'GET', 'path' => '/health', 'status' => 401,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => 'Bearer x',
            'response_body' => '{}',
        ]);
        // No .siro_auth.json → authMode has nothing to refresh; then GET replays
        [$exit, $output] = $this->runCmd(['authnf', '--auth', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying GET', $output);
    }

    public function testAppEnvReadsFromEnvFile(): void
    {
        // No APP_ENV in process env → read from .env file
        putenv('APP_ENV');
        unset($_ENV['APP_ENV']);
        try {
            file_put_contents(
                $this->basePath . DIRECTORY_SEPARATOR . '.env',
                "APP_ENV=staging\n"
            );
            $this->writeTrace('envtrace', [
                'method' => 'GET', 'path' => '/health', 'status' => 200,
                'host' => 'localhost:8080', 'request_body' => '',
            ]);
            // staging counts as production → auto dry-run
            [$exit, $output] = $this->runCmd(['envtrace']);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('Production environment detected', $output);
        } finally {
            $this->restoreBootstrapEnv();
        }
    }

    public function testSafeFlagAndSetSpaceForm(): void
    {
        $this->startServer();
        $this->writeTrace('safe', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"A"}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        // --safe disables force; --set space-separated form and body. prefix
        [$exit, $output] = $this->runCmd(['safe', '--force', '--safe', '--set', 'body.name=B']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('B', $output);
    }

    public function testSeedFlagWithoutValue(): void
    {
        $this->writeTrace('seedflag', [
            'method' => 'POST', 'path' => '/api/products', 'status' => 201,
            'host' => 'localhost:8080',
            'request_body' => '{"name":"Widget"}',
            'table' => 'products',
        ]);
        // --seed (bare flag form) triggers seed mode
        [$exit, $output] = $this->runCmd(['seedflag', '--seed']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Seed command', $output);
    }

    public function testEditModeJsonBoolNullChanges(): void
    {
        $this->startServer();
        $this->writeTrace('edittypes', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"active":true,"count":3,"note":"x","nested":{"a":1}}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        // In-process: empty stdin keeps values (readline returns empty on non-tty).
        [$exit, $output] = $this->runCmdWithInput(['edittypes', '--edit'], "\n\n\n\n");
        $this->assertStringContainsString('Edit request body', $output);
        $this->assertStringContainsString('active', $output);
        $this->assertStringContainsString('nested', $output);
    }

    public function testDiscoverAuthConfigFromUserModel(): void
    {
        $this->assertTrue(class_exists('App\\Models\\User'), 'App\Models\User must exist for discovery');
        $cmd = new LogReplayCommand($this->basePath);
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $m = $ref->getMethod('discoverAuthConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke($cmd);
        // users table (default endpoint) + username/pass fillable → custom fields
        $this->assertSame('/api/auth/login', $cfg['endpoint']);
        $this->assertSame('/api/auth/refresh', $cfg['refresh_endpoint']);
        $this->assertSame('username', $cfg['email_field']);
        $this->assertSame('pass', $cfg['pass_field']);
    }

    public function testIsValidPathEmptyReturnsFalse(): void
    {
        $this->assertFalse(LogReplayCommand::isValidPath(''));
    }

    public function testTraceInvalidPathRejected(): void
    {
        $this->writeTrace('badpath', [
            'method' => 'GET', 'path' => "/api/x\nHost: evil", 'status' => 200,
            'host' => 'localhost:8080', 'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmd(['badpath']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('invalid characters in path', $output);
    }

    public function testSetBodyPrefixForm(): void
    {
        $this->startServer();
        $this->writeTrace('bodyprefix', [
            'method' => 'POST', 'path' => '/echo', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '{"name":"Old"}',
            'request_headers' => ['Content-Type' => 'application/json'],
            'auth_header' => '',
            'response_body' => '{}',
        ]);
        // --set=body.field=value (strips 'body.' prefix)
        [$exit, $output] = $this->runCmd(['bodyprefix', '--force', '--set=body.name=Prefixed']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Prefixed', $output);
    }

    public function testDiffModeCurlError(): void
    {
        $this->writeTrace('difffail', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:1', 'request_body' => '',
            'response_body' => '{"status":"ok"}',
        ]);
        // Port 1 unreachable → curl error in diff mode
        [$exit, $output] = $this->runCmd(['difffail', '--diff']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('curl error', $output);
    }

    public function testDiffModeNonJsonResponse(): void
    {
        $this->startServer();
        $this->writeTrace('diffplain', [
            'method' => 'GET', 'path' => '/plain', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => '',
            'response_body' => 'some plain text',
        ]);
        [$exit, $output] = $this->runCmd(['diffplain', '--diff']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('BEFORE', $output);
        $this->assertStringContainsString('AFTER', $output);
    }

    public function testDiffModeResponseChanged(): void
    {
        $this->startServer();
        $this->writeTrace('diffchanged', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => '',
            'response_body' => '{"status":"ok","old":"field"}',
        ]);
        // Server returns only {"status":"ok"} → body changed detected
        [$exit, $output] = $this->runCmd(['diffchanged', '--diff']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('BEFORE', $output);
    }

    public function testAutoAuth401RetryFlow(): void
    {
        $this->startServer();
        // Trace originally 401 with auth → after replay returns 200, no retry needed.
        $this->writeTrace('auth401', [
            'method' => 'GET', 'path' => '/health', 'status' => 401,
            'host' => '127.0.0.1:' . $this->port,
            'request_body' => '', 'request_headers' => [], 'auth_header' => 'Bearer old',
            'response_body' => '{"message":"unauthorized"}',
        ]);
        // Server returns 200, so the 401 auto-refresh branch is skipped
        [$exit, $output] = $this->runCmd(['auth401', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Replaying GET', $output);
    }
}

namespace App\Models;

use Siro\Core\Model;

class User extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['username', 'pass', 'name'];
}

