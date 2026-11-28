<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Config;
use Siro\Core\Console;
use Siro\Core\Env;

/**
 * Tests for the two commands added after the 27/11 CLI re-eval
 * (schedule:list, route:cache) plus the consistency fixes from the
 * same batch (registry usage strings, make:crud --force, log:top).
 */
final class ScheduleListAndRouteCacheTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        Env::reset();
        Config::reset();

        $this->tempDir = sys_get_temp_dir() . '/siro_sched_route_' . bin2hex(random_bytes(4));
        foreach (['storage/framework', 'storage/logs/traces', 'routes', 'config'] as $dir) {
            @mkdir($this->tempDir . '/' . $dir, 0777, true);
        }

        file_put_contents(
            $this->tempDir . '/.env',
            'APP_NAME="SiroSchedTest"' . "\n"
            . 'APP_ENV=testing' . "\n"
            . 'APP_DEBUG=true' . "\n"
            . 'APP_KEY=testing_app_key_for_hmac_32chars!!' . "\n"
            . 'DB_CONNECTION=sqlite' . "\n"
            . 'DB_DATABASE=:memory:' . "\n"
        );
        file_put_contents(
            $this->tempDir . '/config/database.php',
            '<?php return ["driver" => "sqlite", "database" => ":memory:"];'
        );
        file_put_contents(
            $this->tempDir . '/config/app.php',
            '<?php return ["name" => "SiroSchedTest", "env" => "testing"];'
        );
        // String handler (not a Closure) so the route survives the cache exporter.
        file_put_contents(
            $this->tempDir . '/routes/api.php',
            "<?php\n\$router->get('/api/health', 'HealthController@index');\n"
        );

        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        Env::reset();
        Config::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runCmd(array $argv): array
    {
        ob_start();
        $exit = $this->console->run($argv);
        $output = (string) ob_get_clean();
        return [$exit, $output];
    }

    private function writeSchedule(string $content): void
    {
        file_put_contents($this->tempDir . '/routes/schedule.php', $content);
    }

    // ---------------- schedule:list ----------------

    public function testScheduleListNoFile(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'schedule:list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No schedule file found', $output);
    }

    public function testScheduleListEmptyFile(): void
    {
        $this->writeSchedule("<?php\n// No tasks defined.\n");
        [$exit, $output] = $this->runCmd(['siro', 'schedule:list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No scheduled tasks defined', $output);
    }

    public function testScheduleListShowsTasks(): void
    {
        $this->writeSchedule(
            "<?php\n"
            . "\$schedule->command('queue:work --tries=3')->dailyAt('02:30');\n"
            . "\$schedule->command('db:backup')->hourly();\n"
            . "\$schedule->call(function () {})->everyMinute();\n"
        );
        [$exit, $output] = $this->runCmd(['siro', 'schedule:list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('queue:work --tries=3', $output);
        $this->assertStringContainsString('db:backup', $output);
        $this->assertStringContainsString('30 2 * * *', $output, 'dailyAt(02:30) must show its cron expression');
        $this->assertStringContainsString('0 * * * *', $output, 'hourly() must show its cron expression');
        $this->assertStringContainsString('Closure', $output, 'call() task must be listed');
        $this->assertStringContainsString('Next run', $output);
    }

    public function testScheduleNextRunIsInFuture(): void
    {
        $this->writeSchedule("<?php\n\$schedule->command('db:backup')->daily();\n");
        [$exit, $output] = $this->runCmd(['siro', 'schedule:list']);
        $this->assertSame(0, $exit);
        // daily() = 0 0 * * * — next run is tomorrow 00:00, never a dash.
        $expected = date('Y-m-d', time() + 86400) . ' 00:00';
        $this->assertStringContainsString($expected, $output, 'daily task next run must be tomorrow 00:00');
    }

    // ---------------- route:cache ----------------

    public function testRouteCacheWritesLoadableFile(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'route:cache']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Routes cached successfully', $output);

        $cacheFile = $this->tempDir . '/storage/framework/routes.php';
        $this->assertFileExists($cacheFile);
        $content = (string) file_get_contents($cacheFile);
        $this->assertStringStartsWith('<?php exit; ?>', $content);
        $data = json_decode(ltrim(substr($content, strlen('<?php exit; ?>'))), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('static', $data);
        $this->assertArrayHasKey('dynamic', $data);
        $this->assertNotEmpty($data['hmac'] ?? '', 'cache must be HMAC-signed when APP_KEY is set');

        // Prove the cache really loads: delete the source file, boot a fresh
        // app — loadRoutes() must serve /api/health from the cache alone.
        unlink($this->tempDir . '/routes/api.php');
        $app = new App($this->tempDir);
        $app->boot();
        $app->loadRoutes($this->tempDir . '/routes/api.php');
        $paths = array_map(fn (array $r): string => $r['path'], $app->router->getRoutes());
        $this->assertContains('/api/health', $paths, 'cached routes must load without the source file');
    }

    public function testRouteCacheRebuildsOverStaleCache(): void
    {
        [$exit] = $this->runCmd(['siro', 'route:cache']);
        $this->assertSame(0, $exit);

        // Add a second route, then cache again — the rebuild must pick it up
        // instead of re-caching the stale file.
        file_put_contents(
            $this->tempDir . '/routes/api.php',
            "<?php\n\$router->get('/api/health', 'HealthController@index');\n\$" . "router->get('/api/users', " .
                "'UserController@index');\n"
        );
        [$exit] = $this->runCmd(['siro', 'route:cache']);
        $this->assertSame(0, $exit);

        $app = new App($this->tempDir);
        $app->boot();
        $app->loadRoutes($this->tempDir . '/routes/api.php');
        $paths = array_map(fn (array $r): string => $r['path'], $app->router->getRoutes());
        $this->assertContains('/api/health', $paths);
        $this->assertContains('/api/users', $paths, 'second cache run must include the new route');
    }

    public function testRouteCacheNoRoutesFile(): void
    {
        unlink($this->tempDir . '/routes/api.php');
        [$exit, $output] = $this->runCmd(['siro', 'route:cache']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No routes file found', $output);
    }

    // ---------------- consistency fixes ----------------

    public function testRegistryUsageListsAllParsedFlags(): void
    {
        $ref = new \ReflectionClass(Console::class);
        $m = $ref->getMethod('commandRegistry');
        $m->setAccessible(true);
        $registry = $m->invoke(new Console($this->tempDir));

        foreach (['--version=', '--host=', '--method=', '--path='] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $registry['make:openapi']['usage'],
                "make:openapi usage must list {$flag}"
            );
        }
        foreach (['--public', '--redirect-uri=', '--grants=', '--scopes='] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $registry['make:oauth-client']['usage'],
                "make:oauth-client usage must list {$flag}"
            );
        }
        foreach (
            ['--test', '--auth', '--as=', '--https', '--http', '--insecure', '--seed', '--edit',
            '--diff'] as $flag
        ) {
            $this->assertStringContainsString(
                $flag,
                $registry['log:replay']['usage'],
                "log:replay usage must list {$flag}"
            );
        }
    }

    public function testMakeCrudUsageMentionsForce(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'make:crud']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--force', $output, 'make:crud usage must mention --force');
    }

    public function testLogTopPositionalArgDoesNotBreak(): void
    {
        file_put_contents(
            $this->tempDir . '/storage/logs/traces/trace_001.json',
            '{"trace_id":"trace_001","timestamp":"2026-11-27 10:00:00","method":"GET","path":"/api/test","time_ms":120}'
        );
        // The old dead $args[1] read is gone; a stray positional arg is ignored.
        [$exit] = $this->runCmd(['siro', 'log:top', 'total']);
        $this->assertSame(0, $exit);
        [$exit] = $this->runCmd(['siro', 'log:top', '--limit=5']);
        $this->assertSame(0, $exit);
    }
}
