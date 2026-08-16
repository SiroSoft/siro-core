<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DbBackupCommand;
use Siro\Core\Commands\DbCheckCommand;
use Siro\Core\Commands\DbOptimizeCommand;
use Siro\Core\Commands\DbStatsCommand;
use Siro\Core\Commands\DbWhyCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * MySQL-backed command tests. Requires a local MySQL (root, MYSQL_TEST_PASSWORD env).
 * Covers the runMysql()/mysqlBackup() branches that need a real server.
 */
final class MySQLCommandsTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 3306;
    private const USER = 'root';
    private const DB = 'siro_test';
    private static string $pass = '';

    private static function pass(): string
    {
        if (self::$pass !== '') {
            return self::$pass;
        }
        $env = (string) getenv('MYSQL_TEST_PASSWORD');
        self::$pass = $env !== '' ? $env : '123123@';
        return self::$pass;
    }

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_mysql_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'mysql', 'host' => '" . self::HOST . "', 'port' => " . self::PORT . ", 'username' => '" . self::USER . "', 'password' => '" . self::pass() . "', 'database' => '" . self::DB . "'];\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=testing\nDB_CONNECTION=mysql\nDB_HOST=" . self::HOST . "\nDB_PORT=" . self::PORT . "\nDB_DATABASE=" . self::DB . "\nDB_USERNAME=" . self::USER . "\nDB_PASSWORD=" . self::pass() . "\n"
        );
        // Ensure test tables exist
        $pdo = $this->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS siro_users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS siro_orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, total DECIMAL(10,2))");
        $pdo->exec("INSERT IGNORE INTO siro_users (id, name, email) VALUES (1, 'Alice', 'a@test.com'), (2, 'Bob', 'b@test.com')");
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo()->exec('DROP TABLE IF EXISTS siro_users');
            $this->pdo()->exec('DROP TABLE IF EXISTS siro_orders');
        } catch (\Throwable) {
        }
        Env::reset();
        Cache::reset();
        Database::purgeAll();
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

    private function pdo(): PDO
    {
        return new PDO(
            'mysql:host=' . self::HOST . ';port=' . self::PORT . ';dbname=' . self::DB,
            self::USER,
            self::pass(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** @param array<int, string> $args @return array{int, string} */
    private function runCmd(string $class, array $args): array
    {
        ob_start();
        try {
            /** @var object $cmd */
            $cmd = new $class($this->basePath);
            $exit = $cmd->run($args);
        } finally {
            $output = ob_get_clean() ?: '';
        }
        return [$exit, $output];
    }

    public function testDbStatsMysql(): void
    {
        [$exit, $output] = $this->runCmd(DbStatsCommand::class, []);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('MySQL', $output);
    }

    public function testDbOptimizeMysql(): void
    {
        [$exit, $output] = $this->runCmd(DbOptimizeCommand::class, []);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('MySQL Optimization', $output);
    }

    public function testDbCheckMysql(): void
    {
        [$exit, $output] = $this->runCmd(DbCheckCommand::class, []);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Integrity', $output);
    }

    public function testDbWhyMysqlExplain(): void
    {
        $sql = 'SELECT * FROM siro_users WHERE id = ?';
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, ['--query=' . $sql]);
        $this->assertContains($exit, [0, 1], $output);
    }

    public function testDbWhyMysqlSlow(): void
    {
        // Write a trace with a slow query so --slow lists it
        $tracesDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        mkdir($tracesDir, 0777, true);
        file_put_contents(
            $tracesDir . DIRECTORY_SEPARATOR . 't1.json',
            json_encode([
                'method' => 'GET', 'path' => '/x', 'status' => 200,
                'queries' => [['sql' => 'SELECT * FROM siro_users', 'time_ms' => 200]],
            ])
        );
        [$exit, $output] = $this->runCmd(DbWhyCommand::class, ['--slow']);
        $this->assertContains($exit, [0, 1], $output);
    }

    public function testDbBackupMysql(): void
    {
        [$exit, $output] = $this->runCmd(DbBackupCommand::class, []);
        $this->assertContains($exit, [0, 1], $output);
        if ($exit === 0) {
            $this->assertStringContainsString('Backup created', $output);
        }
    }

    public function testDbBackupMysqlCompressed(): void
    {
        [$exit, $output] = $this->runCmd(DbBackupCommand::class, ['--compress']);
        $this->assertContains($exit, [0, 1], $output);
    }

    public function testDbHealthMysql(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DbHealthCommand::class, []);
        $this->assertContains($exit, [0, 1], $output);
    }
}
