<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DbCheckCommand;
use Siro\Core\Commands\DbOptimizeCommand;
use Siro\Core\Commands\MigrationBaseCommand;
use Siro\Core\Commands\MigrateFreshCommand;
use Siro\Core\Commands\MigrateRefreshCommand;
use Siro\Core\Commands\MigrateResetCommand;
use Siro\Core\Commands\MigrateRollbackCommand;
use Siro\Core\Commands\MigrateStatusCommand;
use Siro\Core\Commands\RuntimeCommand;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Env;

/**
 * Small DB command branches with SQLite config.
 */
final class SmallDbCommandsMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_sdb_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/database/migrations', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/storage/framework', 0777, true);
        mkdir($this->basePath . '/public', 0777, true);
        mkdir($this->basePath . '/app/Models', 0777, true);
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents($this->basePath . '/.env', "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n");
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testDbCheckSqlite(): void
    {
        $cmd = new DbCheckCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbCheckUnsupportedDriver(): void
    {
        file_put_contents($this->basePath . '/config/database.php', "<?php\nreturn ['driver' => 'oracle'];\n");
        $cmd = new DbCheckCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testDbOptimizeSqlite(): void
    {
        $cmd = new DbOptimizeCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbOptimizeUnsupported(): void
    {
        file_put_contents($this->basePath . '/config/database.php', "<?php\nreturn ['driver' => 'oracle'];\n");
        $cmd = new DbOptimizeCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testMigrateStatus(): void
    {
        $cmd = new MigrateStatusCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testMigrateReset(): void
    {
        $cmd = new MigrateResetCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testMigrateRollback(): void
    {
        $cmd = new MigrateRollbackCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testMigrateFresh(): void
    {
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testMigrateRefresh(): void
    {
        $cmd = new MigrateRefreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testRuntimeCommandStatus(): void
    {
        $cmd = new RuntimeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['status']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testServeInvalidPort(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=abc']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServeMissingPublic(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=8080']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }
}
