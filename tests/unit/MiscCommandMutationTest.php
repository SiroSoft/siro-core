<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DoctorCommand;
use Siro\Core\Commands\EnvCacheCommand;
use Siro\Core\Commands\LogCleanupCommand;
use Siro\Core\Commands\LogTailCommand;
use Siro\Core\Env;

/**
 * Coverage tests for LogTail, LogCleanup, EnvCache, Doctor commands.
 */
final class MiscCommandMutationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_misc_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . date('Y-m'), 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework', 0777, true);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        putenv('APP_ENV');
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

    public function testLogTailNoFiles(): void
    {
        $cmd = new LogTailCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testLogTailReadsLines(): void
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . date('Y-m');
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'request-001.log', "GET /a 200 10ms\nPOST /b 500 200ms\nerror occurred\n");

        $cmd = new LogTailCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--type=request', '--lines=10']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Tail:', (string) $out);
        $this->assertStringContainsString('/a', (string) $out);
    }

    public function testLogTailOtherType(): void
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . date('Y-m');
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'error-001.log', "fatal: boom\n");

        $cmd = new LogTailCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--type=error']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testLogCleanupNoDir(): void
    {
        $cmd = new LogCleanupCommand($this->basePath . DIRECTORY_SEPARATOR . 'empty');
        $code = $cmd->run([]);
        $this->assertSame(0, $code);
    }

    public function testLogCleanupDeletesOld(): void
    {
        $traces = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        $old = $traces . DIRECTORY_SEPARATOR . 'old.json';
        $new = $traces . DIRECTORY_SEPARATOR . 'new.json';
        file_put_contents($old, '{}');
        file_put_contents($new, '{}');
        touch($old, time() - 30 * 86400);

        $cmd = new LogCleanupCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--days=7']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function testLogCleanupDryRun(): void
    {
        $traces = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        $old = $traces . DIRECTORY_SEPARATOR . 'old2.json';
        file_put_contents($old, '{}');
        touch($old, time() - 30 * 86400);

        $cmd = new LogCleanupCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--days=7', '--dry-run']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($old);
        $this->assertStringContainsString('Would delete', (string) $out);
    }

    public function testEnvCacheMissingEnv(): void
    {
        $cmd = new EnvCacheCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testEnvCacheSuccess(): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', "APP_ENV=testing\nDB_CONNECTION=sqlite\n");
        $cmd = new EnvCacheCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'env.php');
    }

    public function testDoctorNoEnv(): void
    {
        $cmd = new DoctorCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $out = ob_get_clean();
        $this->assertContains($code, [0, 1]);
        $this->assertStringContainsString('Doctor', (string) $out);
    }

    public function testDoctorWithEnv(): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', "APP_ENV=production\nAPP_DEBUG=false\nDB_CONNECTION=sqlite\nJWT_SECRET=this_is_a_very_long_and_secure_jwt_secret_key_1234\n");
        $cmd = new DoctorCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--prod']);
        $out = ob_get_clean();
        $this->assertContains($code, [0, 1]);
        $this->assertStringContainsString('PHP', (string) $out);
    }
}
