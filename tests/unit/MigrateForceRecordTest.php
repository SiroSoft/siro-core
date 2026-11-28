<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MigrateCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * --force-record was removed in v1.4.0: a failed migration must
 * never be recorded as applied, with or without the flag.
 */
final class MigrateForceRecordTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_mig_fr_' . uniqid();
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
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=testing\nAPP_KEY=this_is_a_sufficiently_long_app_key_12345678\n" .
                "JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678\n" . "DB_CONNECTION=sqlite\n" .
                "DB_DATABASE=" . $this->dbPath . "\n"
        );
        file_put_contents(
            $this->basePath . '/database/migrations/2024_01_01_000001_create_users.php',
            "<?php\nreturn new class {\n    public function up(\\PDO \$pdo): void { \$" .
                "pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name " .
                "TEXT)'); }\n    public function down(\\PDO \$pdo): void { \$pdo->exec('DROP " .
                "TABLE IF EXISTS users'); }\n" . "};\n"
        );
        file_put_contents(
            $this->basePath . '/database/migrations/2024_01_01_000002_broken.php',
            "<?php\nreturn new class {\n    public function up(\\PDO \$pdo): void { \$" .
                "pdo->exec('THIS IS NOT VALID SQL'); }\n    public function down(\\PDO \$pdo): " . "void {}\n" . "};\n"
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

    private function recordedMigrations(): array
    {
        $pdo = Database::connection();
        return $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testFailedMigrationWithForceRecordIsNotRecorded(): void
    {
        $cmd = new MigrateCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force-record']);
        $out = ob_get_clean();
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('force-record', $out);
        $recorded = $this->recordedMigrations();
        $this->assertContains('2024_01_01_000001_create_users.php', $recorded);
        $this->assertNotContains('2024_01_01_000002_broken.php', $recorded);
    }

    public function testFailedMigrationWithoutFlagIsNotRecorded(): void
    {
        $cmd = new MigrateCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
        $recorded = $this->recordedMigrations();
        $this->assertContains('2024_01_01_000001_create_users.php', $recorded);
        $this->assertNotContains('2024_01_01_000002_broken.php', $recorded);
    }
}
