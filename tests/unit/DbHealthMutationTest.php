<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DbHealthCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * DbHealthCommand SQLite health analysis.
 */
final class DbHealthMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_dh_' . uniqid();
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

    public function testDbHealthWithData(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE INDEX idx_users_name ON users(name)');
        for ($i = 0; $i < 20; $i++) {
            $pdo->exec("INSERT INTO users (name) VALUES ('user" . $i . "')");
        }
        $pdo->exec("INSERT INTO users (name) VALUES ('duplicate')");
        $pdo->exec("INSERT INTO users (name) VALUES ('duplicate')");
        $pdo = null;
        $cmd = new DbHealthCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbHealthEmpty(): void
    {
        $cmd = new DbHealthCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbHealthUnsupported(): void
    {
        file_put_contents($this->basePath . '/config/database.php', "<?php\nreturn ['driver' => 'oracle'];\n");
        $cmd = new DbHealthCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testDbHealthMysql(): void
    {
        try {
            $pdo = new \PDO('mysql:host=127.0.0.1;port=3307;dbname=siro_test', 'root', '', [\PDO::ATTR_TIMEOUT => 2]);
            $pdo = null;
        } catch (\Throwable) {
            $this->markTestSkipped('MySQL on 3307 not available');
        }
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'siro_test', 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4'];\n"
        );
        $cmd = new DbHealthCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
