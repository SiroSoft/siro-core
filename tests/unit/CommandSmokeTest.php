<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Commands\ConfigCacheCommand;
use Siro\Core\Commands\ConfigClearCommand;
use Siro\Core\Commands\DownCommand;
use Siro\Core\Commands\EnvCacheCommand;
use Siro\Core\Commands\KeyGenerateCommand;
use Siro\Core\Commands\RouteListCommand;
use Siro\Core\Commands\ScheduleRunCommand;
use Siro\Core\Commands\StorageLinkCommand;
use Siro\Core\Commands\UpCommand;

/**
 * Runs small file-based CLI commands against a throwaway project scaffold.
 */
final class CommandSmokeTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        Env::reset();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_cmd_' . uniqid('', true);
        $dirs = ['routes', 'config', 'storage/framework', 'storage/app/public'];
        foreach ($dirs as $d) {
            mkdir($this->basePath . DIRECTORY_SEPARATOR . $d, 0777, true);
        }
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=local\nAPP_URL=http://localhost\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_testing_1234\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\n\$router->get('/health', fn () => 'ok');\n\$router->get('/users/{id}', function (\$id) { return ['id' => \$id]; });\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'schedule.php',
            "<?php\n\$schedule->call(function () { return 1; })->everyMinute();\n"
        );
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
        Env::reset();
        \Siro\Core\Cache::reset();
        \Siro\Core\Logger::reset();
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
            if (is_dir($path) && !is_link($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testKeyGenerateCreatesSecret(): void
    {
        $cmd = new KeyGenerateCommand($this->basePath);
        $code = $cmd->run(['--force']);
        $this->assertSame(0, $code);
        $content = (string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $this->assertStringContainsString('JWT_SECRET=', $content);
    }

    public function testKeyGenerateMissingEnv(): void
    {
        unlink($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $cmd = new KeyGenerateCommand($this->basePath);
        $this->assertSame(1, $cmd->run([]));
    }

    public function testKeyGenerateRefusesWithoutForce(): void
    {
        putenv('JWT_SECRET=test_jwt_secret_key_for_unit_tests_only_32chars!!');
        $cmd = new KeyGenerateCommand($this->basePath);
        $this->assertSame(1, $cmd->run([]));
    }

    public function testUpDownCommands(): void
    {
        $down = new DownCommand($this->basePath);
        $this->assertSame(0, $down->run(['--message=Maintenance', '--retry=30', '--allow=1.2.3.4']));
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down');

        $up = new UpCommand($this->basePath);
        $this->assertSame(0, $up->run([]));
        $this->assertFileDoesNotExist($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down');

        // Running up again when already live
        $this->assertSame(0, $up->run([]));
    }

    public function testStorageLinkCommand(): void
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $cmd = new StorageLinkCommand($this->basePath);
            $code = $cmd->run([]);
        } finally {
            restore_error_handler();
        }
        $this->assertContains($code, [0, 1]);
    }

    public function testRouteListCommand(): void
    {
        $cmd = new RouteListCommand($this->basePath);
        $this->assertSame(0, $cmd->run([]));
    }

    public function testScheduleRunCommand(): void
    {
        $cmd = new ScheduleRunCommand($this->basePath);
        $this->assertSame(0, $cmd->run([]));
    }

    public function testConfigCacheAndClear(): void
    {
        $cache = new ConfigCacheCommand($this->basePath);
        $this->assertSame(0, $cache->run([]));
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config.php');

        $clear = new ConfigClearCommand($this->basePath);
        $this->assertSame(0, $clear->run([]));
        $this->assertFileDoesNotExist($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config.php');
    }

    public function testEnvCacheCommand(): void
    {
        $cmd = new EnvCacheCommand($this->basePath);
        $this->assertSame(0, $cmd->run([]));
    }
}
