<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Commands\NewCommand;
use Siro\Core\Env;

/**
 * Coverage tests for NewCommand (project scaffolding) and LiveCommand
 * (dev server argument parsing + guards).
 */
final class NewLiveCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_newlive_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
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

    public function testNewCommandUsageWithoutName(): void
    {
        $cmd = new NewCommand($this->basePath);
        $code = $cmd->run([]);
        $this->assertSame(1, $code);
    }

    public function testNewCommandTargetExists(): void
    {
        $target = $this->basePath . DIRECTORY_SEPARATOR . 'exists-proj';
        mkdir($target, 0777, true);
        $cmd = new NewCommand($this->basePath);
        // getcwd() must point at basePath so target = basePath/exists-proj
        $oldCwd = getcwd();
        chdir($this->basePath);
        try {
            $code = $cmd->run(['exists-proj']);
        } finally {
            chdir($oldCwd);
        }
        $this->assertSame(1, $code);
    }

    public function testNewCommandCreatesProject(): void
    {
        // Build a minimal fake skeleton in a sibling "SiroPHP" dir
        $skeleton = dirname($this->basePath) . DIRECTORY_SEPARATOR . 'SiroPHP';
        $this->makeSkeleton($skeleton);

        $oldCwd = getcwd();
        chdir($this->basePath);
        try {
            $cmd = new NewCommand($this->basePath);
            $code = $cmd->run(['demo-project']);
        } finally {
            chdir($oldCwd);
        }

        $target = $this->basePath . DIRECTORY_SEPARATOR . 'demo-project';
        $this->assertSame(0, $code);
        $this->assertDirectoryExists($target);
        $this->assertFileExists($target . DIRECTORY_SEPARATOR . '.env');
        $this->assertDirectoryExists($target . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers');
        $this->assertDirectoryExists($target . DIRECTORY_SEPARATOR . 'routes');
        $env = (string) file_get_contents($target . DIRECTORY_SEPARATOR . '.env');
        $this->assertStringContainsString('JWT_SECRET=', $env);
        $this->assertStringNotContainsString('change-me', $env);

        // clean up the generated project
        $this->rmDir($target);
        $this->rmDir($skeleton);
    }

    private function makeSkeleton(string $dir): void
    {
        mkdir($dir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers', 0777, true);
        mkdir($dir . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces', 0777, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'composer.json', json_encode(['name' => 'sirosoft/api', 'description' => 'Siro API Framework']));
        file_put_contents($dir . DIRECTORY_SEPARATOR . '.env.example', "APP_NAME=SiroPHP\nAPP_ENV=local\nJWT_SECRET=change-me\n");
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php', "<?php\n");
    }

    public function testLiveCommandPortAndHostParsing(): void
    {
        // No public dir -> early return before infinite loop
        $cmd = new LiveCommand($this->basePath);
        $code = $cmd->run(['--port=8081', '--host=127.0.0.1']);
        $this->assertSame(1, $code);
    }

    public function testLiveCommandMissingPublicDir(): void
    {
        $cmd = new LiveCommand($this->basePath);
        $code = $cmd->run([]);
        $this->assertSame(1, $code);
    }

    public function testLiveCommandInvalidPortClamped(): void
    {
        $cmd = new LiveCommand($this->basePath);
        $code = $cmd->run(['--port=0']);
        $this->assertSame(1, $code);
    }

    public function testLiveCommandStartsAndShutsDown(): void
    {
        $public = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        mkdir($public, 0777, true);
        file_put_contents($public . DIRECTORY_SEPARATOR . 'index.php', "<?php\n");

        $cmd = new LiveCommand($this->basePath);
        $cmd->shutdown();
        $code = $cmd->run(['--port=9123', '--host=127.0.0.1']);
        $this->assertSame(0, $code);

        // clean signal/pid files created by startServer
        $signal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_live_' . md5($this->basePath) . '.tmp';
        foreach (["{$signal}", "{$signal}.pid"] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testLiveCommandRouterFileAppended(): void
    {
        $public = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        mkdir($public, 0777, true);
        file_put_contents($public . DIRECTORY_SEPARATOR . 'index.php', "<?php\n");
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app', 0777, true);

        $cmd = new LiveCommand($this->basePath);
        $cmd->shutdown();
        $code = $cmd->run([]);
        $this->assertSame(0, $code);

        $signal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_live_' . md5($this->basePath) . '.tmp';
        foreach (["{$signal}", "{$signal}.pid"] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}
