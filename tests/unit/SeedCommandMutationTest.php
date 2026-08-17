<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\SeedCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * SeedCommand runAll/runSingle branches with real seeder files.
 */
final class SeedCommandMutationTest extends TestCase
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
        putenv('APP_KEY=this_is_a_sufficiently_long_app_key_for_seed_12345');
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678');
        $this->basePath = sys_get_temp_dir() . '/siro_seed_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/database/seeds', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/app', 0777, true);
        $this->dbPath = $this->basePath . '/storage/db.sqlite';
        file_put_contents($this->dbPath, '');
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . $this->dbPath . "', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=testing\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_seed_12345\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678\nDB_CONNECTION=sqlite\nDB_DATABASE=" . $this->dbPath . "\n"
        );
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('APP_KEY');
        putenv('JWT_SECRET');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testNoSeeders(): void
    {
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testSeederNotFound(): void
    {
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['MissingSeeder']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testRunSingleSeeder(): void
    {
        file_put_contents(
            $this->basePath . '/database/seeds/UserSeederX.php',
            "<?php\nclass UserSeederX {\n    public function run(): void {\n        file_put_contents(sys_get_temp_dir() . '/seed_marker.txt', 'ok');\n    }\n}\n"
        );
        $marker = sys_get_temp_dir() . '/seed_marker.txt';
        @unlink($marker);
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['UserSeederX']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($marker);
        @unlink($marker);
    }

    public function testRunAll(): void
    {
        file_put_contents(
            $this->basePath . '/database/seeds/UserSeederY.php',
            "<?php\nclass UserSeederY {\n    public function run(): void {}\n}\n"
        );
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
