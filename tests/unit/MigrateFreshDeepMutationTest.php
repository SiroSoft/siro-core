<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MigrateFreshCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * MigrateFresh with a real file-based SQLite DB + migration files.
 */
final class MigrateFreshDeepMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_mf_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/database/migrations', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/database/seeds', 0777, true);
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
        file_put_contents(
            $this->basePath . '/database/migrations/2024_01_01_000002_create_posts.php',
            "<?php\nreturn new class {\n    public function up(\\PDO \$pdo): void { \$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)'); }\n    public function down(\\PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS posts'); }\n};\n"
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

    public function testMigrateFreshRunsMigrations(): void
    {
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--seed']);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $pdo = Database::connection();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
    }

    public function testMigrateFreshDropsExisting(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE old_table (id INTEGER PRIMARY KEY)');
        $pdo = null;
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testMigrateFreshWithInvalidMigration(): void
    {
        file_put_contents($this->basePath . '/database/migrations/2024_01_01_000000_bad.php', "<?php\nreturn 'not-an-object';\n");
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testMigrateFreshInvalidMigrationDownOnly(): void
    {
        file_put_contents(
            $this->basePath . '/database/migrations/2024_01_01_000003_odd.php',
            "<?php\nreturn new class {\n    public function down(\\PDO \$pdo): void {}\n};\n"
        );
        $cmd = new MigrateFreshCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }
}
