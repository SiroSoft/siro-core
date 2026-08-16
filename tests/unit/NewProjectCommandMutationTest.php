<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\NewProjectCommand;
use Siro\Core\Env;

/**
 * Coverage tests for NewProjectCommand (guard branches).
 */
final class NewProjectCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_np_' . uniqid('', true);
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

    public function testUsageWithoutName(): void
    {
        $cmd = new NewProjectCommand($this->basePath);
        $code = $cmd->run([]);
        $this->assertSame(1, $code);
    }

    public function testWhitespaceNameTreatedAsEmpty(): void
    {
        $cmd = new NewProjectCommand($this->basePath);
        $code = $cmd->run(['   ']);
        $this->assertSame(1, $code);
    }

    public function testDirectoryExists(): void
    {
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'existing', 0777, true);
        $oldCwd = getcwd();
        chdir($this->basePath);
        try {
            $cmd = new NewProjectCommand($this->basePath);
            $code = $cmd->run(['existing']);
        } finally {
            chdir($oldCwd);
        }
        $this->assertSame(1, $code);
    }
}
