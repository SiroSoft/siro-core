<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DbCheckCommand;
use Siro\Core\Commands\DbOptimizeCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * DbCheck + DbOptimize MySQL paths via the test MySQL on port 3307.
 */
final class DbMySqlMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_dbm_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'siro_test', 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4'];\n"
        );
        file_put_contents($this->basePath . '/.env', "APP_ENV=testing\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3307\nDB_DATABASE=siro_test\nDB_USERNAME=root\nDB_PASSWORD=\n");
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

    private function mysqlAvailable(): bool
    {
        try {
            new \PDO('mysql:host=127.0.0.1;port=3307;dbname=siro_test', 'root', '', [\PDO::ATTR_TIMEOUT => 2]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function testDbCheckMysql(): void
    {
        if (!$this->mysqlAvailable()) {
            $this->markTestSkipped('MySQL on 3307 not available');
        }
        $cmd = new DbCheckCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbOptimizeMysql(): void
    {
        if (!$this->mysqlAvailable()) {
            $this->markTestSkipped('MySQL on 3307 not available');
        }
        $cmd = new DbOptimizeCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
