<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DatabaseCommand;
use Siro\Core\Env;

/**
 * Coverage tests for DatabaseCommand (sqlite init, help, dispatch).
 */
final class DatabaseCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_db_' . uniqid('', true);
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

    public function testInitSqliteWithoutEnv(): void
    {
        $oldCwd = getcwd();
        chdir($this->basePath);
        try {
            $cmd = new DatabaseCommand();
            $code = $cmd->run(['init']);
        } finally {
            chdir($oldCwd);
        }
        $this->assertSame(1, $code);
    }

    public function testInitSqliteUpdatesEnv(): void
    {
        $oldCwd = getcwd();
        chdir($this->basePath);
        try {
            file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=old\n");
            mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
            $cmd = new DatabaseCommand();
            $code = $cmd->run(['init']);
        } finally {
            chdir($oldCwd);
        }

        $this->assertSame(0, $code);
        $env = (string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('DB_DATABASE=storage/app/database.sqlite', $env);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'database.sqlite');
    }

    public function testInitSqliteAppendsWhenMissing(): void
    {
        $oldCwd = getcwd();
        chdir($this->basePath);
        try {
            file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', "APP_ENV=testing\n");
            mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
            $cmd = new DatabaseCommand();
            $code = $cmd->run(['init']);
        } finally {
            chdir($oldCwd);
        }

        $this->assertSame(0, $code);
        $env = (string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
    }

    public function testUnknownActionShowsHelp(): void
    {
        $cmd = new DatabaseCommand();
        ob_start();
        $code = $cmd->run(['bogus']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testHelpAction(): void
    {
        $cmd = new DatabaseCommand();
        ob_start();
        $code = $cmd->run(['help']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('db:init', (string) $out);
    }
}
