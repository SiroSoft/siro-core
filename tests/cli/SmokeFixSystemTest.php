<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;
use Siro\Core\Env;
use Siro\Core\Config;
use Siro\Core\Http;
use Siro\Core\Queue;
use Siro\Core\Webhook\WebhookDeliveryJob;

/**
 * Regression tests for the SYSTEM lane of the CLI smoke-fix round
 * (27/11/2026): S-04 env:cache/optimize APP_KEY gate, SYS-M2 env:switch,
 * S-05 webhook whitelist + SSRF opt-in, S-06 route:rules scope/prefix,
 * SYS-M5 env:check warning count, SYS-M8/SYS-M10 api:contract,
 * SYS-M12 replay last-trace tiebreak, benchmark SIGTERM handling.
 */
final class SmokeFixSystemTest extends TestCase
{
    private static string $tempProject;
    private Console $console;

    private const NO_KEY_ENV = 'APP_NAME="SiroTest"' . "\n"
        . 'APP_ENV=testing' . "\n"
        . 'APP_DEBUG=true' . "\n"
        . 'JWT_SECRET=test_jwt_secret_key_for_testing_32chars!!!' . "\n"
        . 'DB_CONNECTION=sqlite' . "\n"
        . 'APP_URL=http://localhost' . "\n";

    public static function setUpBeforeClass(): void
    {
        self::$tempProject = sys_get_temp_dir() . '/siro_smokefix_system_' . bin2hex(random_bytes(4));

        $dirs = [
            'storage/framework',
            'storage/logs/traces',
            'storage/cache',
            'storage/app',
            'storage/public',
            'routes',
            'config',
            'public',
            'app/Controllers',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir(self::$tempProject . '/' . $dir)) {
                mkdir(self::$tempProject . '/' . $dir, 0777, true);
            }
        }

        file_put_contents(self::$tempProject . '/.env', self::NO_KEY_ENV);

        $dbConfig = '<?php return ['
            . "'driver' => 'sqlite',"
            . "'database' => '" . self::$tempProject . "/storage/test.db',"
            . "'slow_query_threshold' => 500,"
            . '];' . "\n";
        file_put_contents(self::$tempProject . '/config/database.php', $dbConfig);

        // Queue tables for webhook:test (Queue::push inserts into jobs).
        $pdo = new \PDO('sqlite:' . self::$tempProject . '/storage/test.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT, data TEXT,'
            . ' attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3, priority INTEGER DEFAULT 0,'
            . ' timeout INTEGER DEFAULT 120, available_at INTEGER DEFAULT 0,'
            . ' locked_until INTEGER NULL, created_at TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' job TEXT, data TEXT, error TEXT, failed_at TEXT)');

        file_put_contents(self::$tempProject . '/routes/api.php', <<<'PHP'
            <?php
            $router->group('/api', [], function (\Siro\Core\Router $router): void {
                $router->post('/auth/register', [AuthController::class, 'register']);
                $router->post('/auth/login', [AuthController::class, 'login']);
                $router->post('/auth/refresh', [AuthController::class, 'refresh']);
                $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
                $router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);
                $router->post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
                $router->post('/auth/verify-email/resend', [AuthController::class, 'resendVerification']);
                $router->get('/auth/me', [AuthController::class, 'me']);
                $router->post('/auth/logout', [AuthController::class, 'logout']);
                $router->get('/users/{id}', [UserController::class, 'show']);
            });
            PHP);

        // Controller with the opening brace on its own line (the S-06
        // trigger): extraction must stop at the end of each method.
        file_put_contents(self::$tempProject . '/app/Controllers/AuthController.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App\Controllers;
            use Siro\Core\Request;
            use Siro\Core\Response;
            final class AuthController
            {
                public function register(Request $request): Response
                {
                    $request->validate([
                        'name' => 'required|min:3|max:120',
                        'email' => 'required|email|max:255',
                        'password' => 'required|min:8|max:255',
                    ]);
                    return new Response([]);
                }
                public function login(Request $request): Response
                {
                    $request->validate([
                        'email' => 'required|email|max:255',
                        'password' => 'required|min:8',
                    ]);
                    return new Response([]);
                }
                public function refresh(Request $request): Response
                {
                    $request->validate(['refresh_token' => 'required']);
                    return new Response([]);
                }
                public function me(Request $request): Response
                {
                    return new Response([]);
                }
                public function logout(Request $request): Response
                {
                    return new Response([]);
                }
                public function verifyEmail(Request $request): Response
                {
                    $request->validate(['token' => 'required']);
                    return new Response([]);
                }
                public function resendVerification(Request $request): Response
                {
                    return new Response([]);
                }
                public function forgotPassword(Request $request): Response
                {
                    $request->validate(['email' => 'required|email']);
                    return new Response([]);
                }
                public function resetPassword(Request $request): Response
                {
                    $request->validate([
                        'token' => 'required',
                        'password' => 'required|min:8',
                    ]);
                    return new Response([]);
                }
            }
            PHP);
    }

    public static function tearDownAfterClass(): void
    {
        self::rmdir(self::$tempProject);
        Env::reset();
        Config::reset();
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }

    protected function setUp(): void
    {
        Env::reset();
        Config::reset();
        // Fresh .env without APP_KEY for every test; tests that need a
        // key append it and reboot the console.
        file_put_contents(self::$tempProject . '/.env', self::NO_KEY_ENV);
        $caches = ['storage/framework/env.php', 'storage/framework/config.php', 'storage/framework/routes.php'];
        foreach ($caches as $cache) {
            $file = self::$tempProject . '/' . $cache;
            if (is_file($file)) {
                unlink($file);
            }
        }
        // Clear trace files left by other tests.
        foreach (glob(self::$tempProject . '/storage/logs/traces/*.json') ?: [] as $trace) {
            unlink($trace);
        }
        // Http SSRF statics must not leak between tests.
        Http::allowPrivateIp(false);
        putenv('WEBHOOK_ALLOW_PRIVATE_IP');
        unset($_ENV['WEBHOOK_ALLOW_PRIVATE_IP'], $_SERVER['WEBHOOK_ALLOW_PRIVATE_IP']);
        $this->console = new Console(self::$tempProject);
    }

    protected function tearDown(): void
    {
        Http::allowPrivateIp(false);
        putenv('WEBHOOK_ALLOW_PRIVATE_IP');
        unset($_ENV['WEBHOOK_ALLOW_PRIVATE_IP'], $_SERVER['WEBHOOK_ALLOW_PRIVATE_IP']);
        Env::reset();
        Config::reset();
    }

    private function runCommand(string $command, array $args = []): array
    {
        $argv = array_merge(['siro', $command], $args);
        ob_start();
        $exitCode = $this->console->run($argv);
        $output = ob_get_clean();
        return [$exitCode, $output];
    }

    /** Re-read the (possibly rewritten) .env into a fresh Console. */
    private function rebootConsole(): void
    {
        Env::reset();
        Config::reset();
        $this->console = new Console(self::$tempProject);
    }

    private function appendEnv(string $line): void
    {
        file_put_contents(self::$tempProject . '/.env', $line . "\n", FILE_APPEND);
    }

    // ==================== S-04: env:cache / optimize APP_KEY gate ====================

    public function testEnvCacheRefusesWithoutAppKey(): void
    {
        [$exitCode, $output] = $this->runCommand('env:cache');
        $this->assertSame(1, $exitCode, 'env:cache without APP_KEY must refuse');
        $this->assertStringContainsString('APP_KEY', $output);
        $this->assertFileDoesNotExist(self::$tempProject . '/storage/framework/env.php');

        // The project must NOT be bricked: other commands still run.
        [$clearExit] = $this->runCommand('config:clear');
        $this->assertSame(0, $clearExit, 'config:clear must still work after refused env:cache');
    }

    public function testEnvCacheRefusesShortAppKey(): void
    {
        $this->appendEnv('APP_KEY=short123');
        $this->rebootConsole();
        [$exitCode] = $this->runCommand('env:cache');
        $this->assertSame(1, $exitCode, 'env:cache with an 8-char APP_KEY must refuse');
        $this->assertFileDoesNotExist(self::$tempProject . '/storage/framework/env.php');
    }

    public function testEnvCacheSucceedsWithAppKey(): void
    {
        $this->appendEnv('APP_KEY=0123456789abcdef0123456789abcdef');
        $this->rebootConsole();
        [$exitCode, $output] = $this->runCommand('env:cache');
        $this->assertSame(0, $exitCode, 'env:cache with a 32-char APP_KEY must succeed');
        $this->assertStringContainsString('Env cached', $output);
        $this->assertFileExists(self::$tempProject . '/storage/framework/env.php');

        // ...and config:clear can still clean up afterwards.
        [$clearExit] = $this->runCommand('config:clear');
        $this->assertSame(0, $clearExit);
        $this->assertFileDoesNotExist(self::$tempProject . '/storage/framework/env.php');
    }

    public function testOptimizeRefusesWithoutAppKey(): void
    {
        [$exitCode, $output] = $this->runCommand('optimize');
        $this->assertSame(1, $exitCode, 'optimize without APP_KEY must refuse');
        $this->assertStringContainsString('APP_KEY', $output);
        $this->assertFileDoesNotExist(self::$tempProject . '/storage/framework/env.php');
        $this->assertFileDoesNotExist(self::$tempProject . '/storage/framework/config.php');
        $this->assertFileDoesNotExist(self::$tempProject . '/storage/framework/routes.php');
    }

    public function testOptimizeSucceedsWithAppKey(): void
    {
        $this->appendEnv('APP_KEY=0123456789abcdef0123456789abcdef');
        $this->rebootConsole();
        [$exitCode] = $this->runCommand('optimize');
        $this->assertSame(0, $exitCode, 'optimize with a 32-char APP_KEY must succeed');
        $this->assertFileExists(self::$tempProject . '/storage/framework/env.php');
    }

    // ==================== SYS-M2: env:switch ====================

    public function testEnvSwitchMissingEnvWithBackupFails(): void
    {
        file_put_contents(self::$tempProject . '/.env.testing', "APP_ENV=testing\nAPP_DEBUG=true\n");
        [$switchExit] = $this->runCommand('env:switch', ['testing']);
        $this->assertSame(0, $switchExit, 'switch to an existing env must succeed');
        $this->assertFileExists(self::$tempProject . '/.env.backup');
        $envBefore = (string) file_get_contents(self::$tempProject . '/.env');

        [$exitCode, $output] = $this->runCommand('env:switch', ['production']);
        $this->assertSame(1, $exitCode, 'switch to a missing env must fail even with a backup present');
        $this->assertStringContainsString('Environment file not found', $output);
        $this->assertStringNotContainsString('restored from .env.backup', $output);
        $this->assertSame(
            $envBefore,
            (string) file_get_contents(self::$tempProject . '/.env'),
            '.env must be untouched'
        );

        [$exitCode2] = $this->runCommand('env:switch', ['notanenv']);
        $this->assertSame(1, $exitCode2);
        $this->assertSame(
            $envBefore,
            (string) file_get_contents(self::$tempProject . '/.env'),
            '.env must still be untouched'
        );
    }

    // ==================== S-05: webhook whitelist + SSRF opt-in ====================

    public function testWebhookDeliveryJobIsAutoWhitelisted(): void
    {
        Queue::fake();
        Queue::push(WebhookDeliveryJob::class, [
            'url' => 'http://127.0.0.1:9/hook', 'event' => 'order.paid', 'payload' => [], 'secret' => 's3cr3t',
        ]);
        $this->assertTrue(
            Queue::isJobAllowed(WebhookDeliveryJob::class),
            'WebhookDeliveryJob must be auto-whitelisted like SendMailJob'
        );
        Queue::reset();
    }

    public function testHttpSsrfBlocksPrivateIpByDefault(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSRF blocked');
        Http::post('http://127.0.0.1:9/hook', '{}', ['Content-Type' => 'application/json']);
    }

    public function testHttpSsrfAllowsPrivateIpWithEnvOptIn(): void
    {
        putenv('WEBHOOK_ALLOW_PRIVATE_IP=true');
        $_ENV['WEBHOOK_ALLOW_PRIVATE_IP'] = 'true';
        $this->assertTrue(Http::isPrivateIpAllowed());
        try {
            Http::post('http://127.0.0.1:9/hook', '{}', ['Content-Type' => 'application/json']);
            $this->fail('Expected a connection failure, not a pass-through');
        } catch (\RuntimeException $e) {
            // Port 9 is closed: the request must now fail at CONNECT, not at the SSRF guard.
            $this->assertStringNotContainsString('SSRF blocked', $e->getMessage());
            $this->assertStringContainsString('HTTP request failed', $e->getMessage());
        }
    }

    public function testHttpSsrfAllowsPrivateIpWithSetter(): void
    {
        Http::allowPrivateIp(true);
        $this->assertTrue(Http::isPrivateIpAllowed());
        try {
            Http::post('http://127.0.0.1:9/hook', '{}', ['Content-Type' => 'application/json']);
            $this->fail('Expected a connection failure, not a pass-through');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('SSRF blocked', $e->getMessage());
        }
    }

    public function testHttpMetadataHostStillBlockedWhenPrivateIpAllowed(): void
    {
        Http::allowPrivateIp(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSRF blocked: target host is blocked');
        Http::post('http://169.254.169.254/latest/meta-data/', null, []);
    }

    public function testWebhookTestAllowPrivateIpPersistsOptIn(): void
    {
        [$exitCode, $output] = $this->runCommand('webhook:test', [
            'order.paid',
            '--url=http://127.0.0.1:18155/hook',
            '--secret=s3cr3t',
            "--payload={\"order_id\":42}",
            '--allow-private-ip',
        ]);
        $this->assertSame(0, $exitCode, 'webhook:test --allow-private-ip must queue: ' . $output);
        $envContent = (string) file_get_contents(self::$tempProject . '/.env');
        $this->assertStringContainsString('WEBHOOK_ALLOW_PRIVATE_IP=true', $envContent);

        $pdo = new \PDO('sqlite:' . self::$tempProject . '/storage/test.db');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE job = ?');
        $stmt->execute([WebhookDeliveryJob::class]);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(1, $count, 'one WebhookDeliveryJob must be queued');
    }

    public function testWebhookTestWarnsAboutPrivateUrlWithoutOptIn(): void
    {
        [$exitCode, $output] = $this->runCommand('webhook:test', [
            'order.paid',
            '--url=http://127.0.0.1:18155/hook',
            '--secret=s3cr3t',
        ]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('private/local address', $output);
        $this->assertStringContainsString('WEBHOOK_ALLOW_PRIVATE_IP', $output);
    }

    // ==================== S-06: route:rules ====================

    public function testRouteRulesScopedToMethodAndPrefixed(): void
    {
        [$exitCode, $output] = $this->runCommand('route:rules');
        $this->assertSame(0, $exitCode, 'route:rules must exit 0');
        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $output);

        // /api prefix on every route.
        $this->assertStringContainsString('POST /api/auth/register', $plain);
        $this->assertStringNotContainsString('POST /auth/register', $plain);

        // register: exactly its own rules, email rule NOT overwritten
        // by forgotPassword's shorter rule.
        $register = $this->blockBetween($plain, 'POST /api/auth/register', 'POST /api/auth/login');
        $this->assertStringContainsString('name: required|min:3|max:120', $register);
        $this->assertStringContainsString('email: required|email|max:255', $register);
        $this->assertStringContainsString('password: required|min:8|max:255', $register);
        $this->assertStringNotContainsString('refresh_token', $register);
        $this->assertStringNotContainsString('token:', $register);

        // refresh: only refresh_token.
        $refresh = $this->blockBetween($plain, 'POST /api/auth/refresh', 'POST /api/auth/forgot-password');
        $this->assertStringContainsString('refresh_token', $refresh);
        $this->assertStringNotContainsString('email', $refresh);
        $this->assertStringNotContainsString('password', $refresh);

        // Methods without validate() print nothing at all.
        $this->assertStringNotContainsString('/api/auth/me', $plain);
        $this->assertStringNotContainsString('/api/auth/logout', $plain);
        $this->assertStringNotContainsString('/api/auth/verify-email/resend', $plain);

        // reset-password (last validate() in the file) still correct.
        $reset = $this->blockBetween($plain, 'POST /api/auth/reset-password', 'POST /api/auth/verify-email');
        $this->assertStringContainsString('token: required', $reset);
        $this->assertStringContainsString('password: required|min:8', $reset);
    }

    private function blockBetween(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        if ($start === false) {
            return '';
        }
        $end = strpos($text, $to, $start + strlen($from));
        if ($end === false) {
            return substr($text, $start);
        }
        return substr($text, $start, $end - $start);
    }

    // ==================== SYS-M5: env:check warning count ====================

    public function testEnvCheckCountsWarnings(): void
    {
        [$exitCode, $output] = $this->runCommand('env:check');
        $this->assertSame(0, $exitCode, 'env:check must still exit 0 with only warnings');
        // The summary must reflect every [WARN] line printed above, whatever
        // the environment: redis is the only optional extension in this temp
        // project (DB_CONNECTION=sqlite, so no MySQL warnings), so the count
        // is 1 when redis is missing and 0 when it is loaded. Asserting a
        // fixed count would fail on any machine with phpredis installed.
        if (extension_loaded('redis')) {
            $this->assertStringNotContainsString('[WARN] redis not loaded', $output);
            $this->assertStringContainsString(
                '0 warnings',
                $output,
                'summary must count 0 warnings when redis is loaded'
            );
        } else {
            $this->assertStringContainsString('[WARN] redis not loaded', $output);
            $this->assertStringContainsString('1 warnings', $output, 'summary must count the redis WARN');
        }
        $this->assertMatchesRegularExpression(
            '/Results: \d+ passed, ' . substr_count($output, '[WARN]') . ' warnings, \d+ failed/',
            $output,
            'summary warning count must equal the number of [WARN] lines'
        );
    }

    // ==================== SYS-M8 / SYS-M10: api:contract ====================

    private function writeSpec(string $name, string $json): string
    {
        $path = self::$tempProject . '/' . $name;
        file_put_contents($path, $json);
        return $path;
    }

    public function testApiContractPathFilterWithNoMatchFails(): void
    {
        $spec = $this->writeSpec('spec-good.json', '{"openapi":"3.0.0","info":{"title":"t","version":"1"},'
            . '"paths":{"/health":{"get":{"responses":{"200":{"description":"ok"}}}}}}');
        [$exitCode, $output] = $this->runCommand('api:contract', ['--spec=' . $spec, '--path=/no-such-path']);
        $this->assertSame(1, $exitCode, '0 checks must not exit 0');
        $this->assertStringContainsString('No operations matched', $output);
        $this->assertStringContainsString('Contract: 0 passed, 0 warnings, 0 failed', $output);
    }

    public function testApiContractPathFilterWithMatchStillPasses(): void
    {
        $spec = $this->writeSpec('spec-me.json', '{"openapi":"3.0.0","info":{"title":"t","version":"1"},'
            . '"paths":{"/api/auth/me":{"get":{"responses":{"200":{"description":"ok"}}}}}}');
        [$exitCode, $output] = $this->runCommand('api:contract', ['--spec=' . $spec, '--path=/api/auth']);
        $this->assertSame(0, $exitCode, 'a matching --path filter must still pass: ' . $output);
        $this->assertStringContainsString('Contract: 1 passed, 0 warnings, 0 failed', $output);
    }

    public function testApiContractPlaceholderDoesNotMatchLiteralRoute(): void
    {
        $spec = $this->writeSpec('spec-id.json', '{"openapi":"3.0.0","info":{"title":"t","version":"1"},'
            . '"paths":{"/api/auth/{id}":{"get":{"responses":{"200":{"description":"ok"}}}}}}');
        [$exitCode, $output] = $this->runCommand('api:contract', ['--spec=' . $spec]);
        $this->assertSame(1, $exitCode, 'GET /api/auth/{id} must not pass via GET /api/auth/me: ' . $output);
        $this->assertStringContainsString('Missing route: GET /api/auth/{id}', $output);
    }

    public function testApiContractPlaceholderMatchesRealParameterizedRoute(): void
    {
        $spec = $this->writeSpec('spec-user.json', '{"openapi":"3.0.0","info":{"title":"t","version":"1"},'
            . '"paths":{"/api/users/{id}":{"get":{"responses":{"200":{"description":"ok"}}}}}}');
        [$exitCode, $output] = $this->runCommand('api:contract', ['--spec=' . $spec]);
        $this->assertSame(0, $exitCode, 'a real parameterized route must still match: ' . $output);
        $this->assertStringContainsString('Contract: 1 passed, 0 warnings, 0 failed', $output);
    }

    // ==================== SYS-M12: replay last-trace tiebreak ====================

    public function testReplayPicksNewestByContentTimestamp(): void
    {
        $dir = self::$tempProject . '/storage/logs/traces';
        $old = [
            'method' => 'POST', 'path' => '/api/upload', 'status' => 401,
            'timestamp' => '2026-11-27 03:15:19', 'trace_id' => 'siro_oldtrace000000000000000000000001',
        ];
        $new = [
            'method' => 'GET', 'path' => '/health', 'status' => 401,
            'timestamp' => '2026-11-27 16:40:18', 'trace_id' => 'siro_newtrace000000000000000000000002',
        ];
        file_put_contents($dir . '/siro_oldtrace000000000000000000000001.json', (string) json_encode($old));
        file_put_contents($dir . '/siro_newtrace000000000000000000000002.json', (string) json_encode($new));
        // Force identical mtimes: the old filemtime-only sort tied here.
        $mtime = time();
        touch($dir . '/siro_oldtrace000000000000000000000001.json', $mtime);
        touch($dir . '/siro_newtrace000000000000000000000002.json', $mtime);

        [$exitCode, $output] = $this->runCommand('replay');
        // Exit 1 is expected: replaying GET /health curls localhost:8080,
        // which is not listening in tests. The assertion is the CHOICE.
        $this->assertStringContainsString('Replaying last trace: siro_newtrace000000000000000000000002', $output);
        $this->assertStringNotContainsString('siro_oldtrace000000000000000000000001', $output);
        $this->assertSame(1, $exitCode);
    }

    // ==================== Benchmark SIGTERM ====================

    public function testBenchmarkEnablesAsyncSignals(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl not available');
        }
        [$exitCode] = $this->runCommand('benchmark', ['--iterations=1', '--warmup=0', '--json']);
        $this->assertSame(0, $exitCode);
        $this->assertTrue(
            pcntl_async_signals(),
            'benchmark must enable async signals so Console SIGTERM handler fires'
        );
    }
}
