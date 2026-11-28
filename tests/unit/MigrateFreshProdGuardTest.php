<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MigrateFreshCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * migrate:fresh must refuse to run in production without --force.
 * The tests run non-interactively (no TTY), so the prompt path
 * cannot be taken: without --force the command must abort.
 */
final class MigrateFreshProdGuardTest extends TestCase
{
    private string $basePath;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        $this->basePath = sys_get_temp_dir() . '/siro_mf_prod_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/database/migrations', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/database/seeds', 0777, true);
        $this->dbPath = $this->basePath . '/storage/db.sqlite';
        file_put_contents($this->dbPath, '');
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . $this
                ->dbPath . "', 'slow_query_threshold' => 500];\n"
        );
        $this->writeEnv('testing');
        file_put_contents(
            $this->basePath . '/database/migrations/2024_01_01_000001_create_users.php',
            "<?php\nreturn new class {\n    public function up(\\PDO \$pdo): void { \$" .
                "pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name " .
                "TEXT)'); }\n    public function down(\\PDO \$pdo): void { \$pdo->exec('DROP " .
                "TABLE IF EXISTS users'); }\n" . "};\n"
        );
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        if (is_dir($this->basePath)) {
            \Siro\Core\Tests\TestFilesystem::removeDirectory($this->basePath);
        }
        parent::tearDown();
    }

    private function writeEnv(string $appEnv): void
    {
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=" . $appEnv . "\nAPP_KEY=this_is_a_sufficiently_long_app_key_12345678\n" .
                "JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678\n" . "DB_CONNECTION=sqlite\n" .
                "DB_DATABASE=" . $this->dbPath . "\n"
        );
    }

    private function tableExists(string $table): bool
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'")->fetchAll();
        $pdo = null;
        return $rows !== [];
    }

    public function testProductionWithoutForceAbortsAndKeepsTables(): void
    {
        $this->writeEnv('production');
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE old_table (id INTEGER PRIMARY KEY)');
        $pdo = null;

        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $out = ob_get_clean();
        $this->assertSame(1, $code, $out);
        $this->assertTrue($this->tableExists('old_table'), 'guard must not drop tables');
        $this->assertFalse($this->tableExists('users'), 'guard must not run migrations');
    }

    public function testProductionWithForceRuns(): void
    {
        $this->writeEnv('production');
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $this->assertTrue($this->tableExists('users'));
    }

    public function testNonProductionRunsWithoutForce(): void
    {
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertTrue($this->tableExists('users'));
    }
}
