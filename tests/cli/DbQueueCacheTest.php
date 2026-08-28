<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Cache;
use Siro\Core\Logger;

class DbQueueCacheTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro_db_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/database/migrations', 0777, true);
        mkdir($this->tempDir . '/database/seeds', 0777, true);
        mkdir($this->tempDir . '/config', 0777, true);
        mkdir($this->tempDir . '/storage/framework', 0777, true);
        mkdir($this->tempDir . '/storage/logs', 0777, true);
        mkdir($this->tempDir . '/storage/cache', 0777, true);
        mkdir($this->tempDir . '/app', 0777, true);
        mkdir($this->tempDir . '/routes', 0777, true);

        file_put_contents($this->tempDir . '/.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=testing_app_key_for_hmac_32chars!!\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nJWT_SECRET=test_jwt_secret_key_for_unit_tests_only_32chars!!\n");

        file_put_contents($this->tempDir . '/config/database.php',
            '<?php return ["driver" => "sqlite", "database" => ":memory:", "charset" => "utf8mb4", "slow_query_threshold" => 500];');

        file_put_contents($this->tempDir . '/routes/schedule.php',
            "<?php\n\ndeclare(strict_types=1);\n");

        // Reset all singletons to isolate tests
        Env::reset();
        Database::purgeAll();
        Cache::reset();
        Logger::reset();

        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        Env::reset();
        Database::purgeAll();
        Cache::reset();
        Logger::reset();
        $this->rrmdir($this->tempDir);
    }

    // ==================== DATABASE COMMANDS ====================

    public function testMakeMigration(): void
    {
        $exitCode = $this->console->run(['siro', 'make:migration', 'create_test_table']);
        $this->assertEquals(0, $exitCode, 'make:migration should exit 0');

        $files = glob($this->tempDir . '/database/migrations/*.php');
        $this->assertNotEmpty($files, 'Migration file should exist');
    }

    public function testMigrate(): void
    {
        $this->console->run(['siro', 'make:migration', 'create_test_table']);

        $exitCode = $this->console->run(['siro', 'migrate']);
        $this->assertEquals(0, $exitCode, 'migrate should exit 0');
    }

    public function testMigrateStatus(): void
    {
        $this->console->run(['siro', 'make:migration', 'create_test_table']);
        $this->console->run(['siro', 'migrate']);

        $exitCode = $this->console->run(['siro', 'migrate:status']);
        $this->assertEquals(0, $exitCode, 'migrate:status should exit 0');
    }

    public function testDbSeed(): void
    {
        $exitCode = $this->console->run(['siro', 'db:seed']);
        $this->assertEquals(0, $exitCode, 'db:seed should exit 0');
    }

    public function testDbShowSchema(): void
    {
        $this->console->run(['siro', 'make:migration', 'create_test_table']);
        $this->console->run(['siro', 'migrate']);

        $exitCode = $this->console->run(['siro', 'db:show', 'migrations', '--schema']);
        $this->assertEquals(0, $exitCode, 'db:show migrations --schema should exit 0');
    }

    // ==================== QUEUE COMMANDS ====================

    public function testQueueStatus(): void
    {
        $exitCode = $this->console->run(['siro', 'queue:status']);
        $this->assertEquals(0, $exitCode, 'queue:status should exit 0');
    }

    public function testQueueFlush(): void
    {
        $exitCode = $this->console->run(['siro', 'queue:flush', '--yes']);
        $this->assertEquals(0, $exitCode, 'queue:flush should exit 0');
    }

    // ==================== CACHE COMMANDS ====================

    public function testConfigCache(): void
    {
        $exitCode = $this->console->run(['siro', 'config:cache']);
        $this->assertEquals(0, $exitCode, 'config:cache should exit 0');
    }

    public function testConfigClear(): void
    {
        $exitCode = $this->console->run(['siro', 'config:clear']);
        $this->assertEquals(0, $exitCode, 'config:clear should exit 0');
    }

    public function testEnvCache(): void
    {
        $exitCode = $this->console->run(['siro', 'env:cache']);
        $this->assertEquals(0, $exitCode, 'env:cache should exit 0');
    }

    // ==================== SCHEDULE ====================

    public function testScheduleRun(): void
    {
        $exitCode = $this->console->run(['siro', 'schedule:run']);
        $this->assertEquals(0, $exitCode, 'schedule:run should exit 0');
    }

    // ==================== INTEGRATION: FULL MIGRATION LIFECYCLE ====================

    public function testFullMigrationLifecycle(): void
    {
        // make:migration
        $exitCode = $this->console->run(['siro', 'make:migration', 'create_users_table']);
        $this->assertEquals(0, $exitCode, 'make:migration should exit 0');

        $files = glob($this->tempDir . '/database/migrations/*.php');
        $this->assertNotEmpty($files, 'Migration file should exist');

        // migrate (run all pending)
        $exitCode = $this->console->run(['siro', 'migrate']);
        $this->assertEquals(0, $exitCode, 'migrate should exit 0');

        // migrate:status (should show migration as [Y])
        $exitCode = $this->console->run(['siro', 'migrate:status']);
        $this->assertEquals(0, $exitCode, 'migrate:status should exit 0');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath());
            else unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
