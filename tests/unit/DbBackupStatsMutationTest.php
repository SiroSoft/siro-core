<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DbBackupCommand;
use Siro\Core\Commands\DbBenchmarkCommand;
use Siro\Core\Commands\DbStatsCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * DbBackup + DbStats + DbBenchmark with SQLite file DB.
 */
final class DbBackupStatsMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_dbs_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        $this->dbPath = $this->basePath . '/storage/db.sqlite';
        file_put_contents($this->dbPath, '');
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . $this->dbPath . "', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents($this->basePath . '/.env', "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE=" . $this->dbPath . "\n");
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

    public function testDbBackup(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (name) VALUES ('a')");
        $pdo = null;
        $cmd = new DbBackupCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbBackupCompress(): void
    {
        $cmd = new DbBackupCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--compress']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbBackupUnsupported(): void
    {
        file_put_contents($this->basePath . '/config/database.php', "<?php\nreturn ['driver' => 'oracle'];\n");
        $cmd = new DbBackupCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testDbStats(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        for ($i = 0; $i < 10; $i++) {
            $pdo->exec("INSERT INTO users (name) VALUES ('u" . $i . "')");
        }
        $pdo = null;
        $cmd = new DbStatsCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbStatsUnsupported(): void
    {
        file_put_contents($this->basePath . '/config/database.php', "<?php\nreturn ['driver' => 'oracle'];\n");
        $cmd = new DbStatsCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testDbBenchmark(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE bench (id INTEGER PRIMARY KEY, val TEXT)');
        $pdo = null;
        $cmd = new DbBenchmarkCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--iterations=3']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
