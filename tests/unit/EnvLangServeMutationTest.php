<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\EnvSwitchCommand;
use Siro\Core\Commands\MakeLangCommand;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Env;

/**
 * Coverage tests for EnvSwitchCommand, MakeLangCommand, ServeCommand guards.
 */
final class EnvLangServeMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_els_' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
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

    public function testEnvSwitchInvalidEnv(): void
    {
        $cmd = new EnvSwitchCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['not valid!']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testEnvSwitchNoProfile(): void
    {
        $cmd = new EnvSwitchCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['production']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testEnvSwitchRestoresFromBackup(): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', 'BACKUP_CONTENT');
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env.backup', 'RESTORED');
        unlink($this->basePath . DIRECTORY_SEPARATOR . '.env');

        $cmd = new EnvSwitchCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['staging']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertSame('RESTORED', (string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . '.env'));
    }

    public function testEnvSwitchSuccess(): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', 'ORIGINAL');
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env.testing', 'TESTING_ENV');

        $cmd = new EnvSwitchCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['testing']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertSame('TESTING_ENV', (string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . '.env'));
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . '.env.backup');
    }

    public function testMakeLangUsage(): void
    {
        $cmd = new MakeLangCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testMakeLangMessagesTemplate(): void
    {
        $cmd = new MakeLangCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['en']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'messages.php');
        $this->assertStringContainsString('messages', (string) $out);
    }

    public function testMakeLangValidationVietnamese(): void
    {
        $cmd = new MakeLangCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['vi', 'validation']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $f = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'vi' . DIRECTORY_SEPARATOR . 'validation.php';
        $this->assertFileExists($f);
        $this->assertStringContainsString('Thông báo', (string) file_get_contents($f));
    }

    public function testMakeLangDefaultTemplate(): void
    {
        $cmd = new MakeLangCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['fr', 'custom']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'fr' . DIRECTORY_SEPARATOR . 'custom.php');
    }

    public function testMakeLangSkipOnExisting(): void
    {
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en', 0777, true);
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'messages.php', 'EXISTING');

        $cmd = new MakeLangCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['en']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Skipped', (string) $out);
        $this->assertSame('EXISTING', (string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'messages.php'));
    }

    public function testServeInvalidHost(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['bad host!']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServeInvalidPort(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=abc']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServeMissingPublicDir(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=8080']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServeMissingRouterScript(): void
    {
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'public', 0777, true);
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=8080']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }
}
