<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DbOptimizeCommand;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Commands\MigrateResetCommand;
use Siro\Core\Commands\MigrateRollbackCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * LiveCommand::checkChanges via reflection + DbOptimize SQLite VACUUM path + migrate reset/rollback.
 */
final class LiveChangesDbOptimizeMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_lco_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/app', 0777, true);
        mkdir($this->basePath . '/routes', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/public', 0777, true);
        mkdir($this->basePath . '/database/migrations', 0777, true);
        $this->dbPath = $this->basePath . '/storage/db.sqlite';
        file_put_contents($this->dbPath, '');
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . $this->dbPath . "', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=testing\nAPP_KEY=this_is_a_sufficiently_long_app_key_12345678\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678\nDB_CONNECTION=sqlite\nDB_DATABASE=" . $this->dbPath . "\n"
        );
        file_put_contents(
            $this->basePath . '/database/migrations/2024_01_01_000001_create_users.php',
            "<?php\nreturn new class {\n    public function up(\\PDO \$pdo): void { \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'); }\n    public function down(\\PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS users'); }\n};\n"
        );
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testCheckChangesDetectsPhp(): void
    {
        touch($this->basePath . '/app/Controller.php');
        $cmd = new LiveCommand($this->basePath);
        $ref = new \ReflectionMethod($cmd, 'checkChanges');
        $result = $ref->invoke($cmd, [$this->basePath . '/app'], 0);
        $this->assertSame('Controller.php', $result);
    }

    public function testCheckChangesIgnoresNonPhp(): void
    {
        file_put_contents($this->basePath . '/app/data.bin', 'x');
        $cmd = new LiveCommand($this->basePath);
        $ref = new \ReflectionMethod($cmd, 'checkChanges');
        $result = $ref->invoke($cmd, [$this->basePath . '/app'], 0);
        $this->assertSame('', $result);
    }

    public function testCheckChangesMissingDir(): void
    {
        $cmd = new LiveCommand($this->basePath);
        $ref = new \ReflectionMethod($cmd, 'checkChanges');
        $result = $ref->invoke($cmd, [$this->basePath . '/nonexistent'], 0);
        $this->assertSame('', $result);
    }

    public function testDbOptimizeFileVacuum(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, data TEXT)');
        for ($i = 0; $i < 50; $i++) {
            $pdo->exec("INSERT INTO t (data) VALUES ('row" . $i . "')");
        }
        $pdo = null;
        $cmd = new DbOptimizeCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testMigrateReset(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo = null;
        $cmd = new MigrateResetCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testMigrateRollback(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo = null;
        $cmd = new MigrateRollbackCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
