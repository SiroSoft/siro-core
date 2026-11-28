<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\SeedCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * SeedCommand with namespaced seeders and a configurable SEED_PATH.
 */
final class SeedCommandNamespaceTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_seed_ns_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/database/seeds', 0777, true);
        mkdir($this->basePath . '/database/seeders', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/app', 0777, true);
        $this->dbPath = $this->basePath . '/storage/db.sqlite';
        file_put_contents($this->dbPath, '');
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . $this
                ->dbPath . "', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=testing\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_seed_12345\n" .
                "JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678\n" . "DB_CONNECTION=sqlite\n" .
                "DB_DATABASE=" . $this->dbPath . "\n"
        );
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('APP_KEY');
        putenv('JWT_SECRET');
        putenv('SEED_PATH');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        if (is_dir($this->basePath)) {
            \Siro\Core\Tests\TestFilesystem::removeDirectory($this->basePath);
        }
        parent::tearDown();
    }

    public function testRunSingleByFullyQualifiedName(): void
    {
        $marker = sys_get_temp_dir() . '/seed_ns_marker_a.txt';
        @unlink($marker);
        file_put_contents(
            $this->basePath . '/database/seeders/Dev2NsSeederA.php',
            "<?php\nnamespace Database\\Seeders;\nclass Dev2NsSeederA {\n    public " .
                "function run(): void {\n        " . "file_put_contents('" . $marker . "', 'ok');\n    }\n}\n"
        );
        putenv('SEED_PATH=database/seeders');
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['Database\\Seeders\\Dev2NsSeederA']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($marker);
        @unlink($marker);
    }

    public function testRunSingleByShortName(): void
    {
        $marker = sys_get_temp_dir() . '/seed_ns_marker_b.txt';
        @unlink($marker);
        file_put_contents(
            $this->basePath . '/database/seeders/Dev2NsSeederB.php',
            "<?php\nnamespace Database\\Seeders;\nclass Dev2NsSeederB {\n    public " .
                "function run(): void {\n        " . "file_put_contents('" . $marker . "', 'ok');\n    }\n}\n"
        );
        putenv('SEED_PATH=database/seeders');
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['Dev2NsSeederB']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($marker);
        @unlink($marker);
    }

    public function testRunAllWithDatabaseSeederCalls(): void
    {
        $marker = sys_get_temp_dir() . '/seed_ns_marker_c.txt';
        @unlink($marker);
        file_put_contents(
            $this->basePath . '/database/seeders/Dev2NsSeederC.php',
            "<?php\nnamespace Database\\Seeders;\nclass Dev2NsSeederC {\n    public " .
                "function run(): void {\n        " . "file_put_contents('" . $marker . "', 'ok');\n    }\n}\n"
        );
        file_put_contents(
            $this->basePath . '/database/seeders/DatabaseSeeder.php',
            "<?php\nnamespace Database\\Seeders;\nclass DatabaseSeeder {\n    /** @var " .
                "array<int, string> */\n    public array \$calls = [Dev2NsSeederC::class];\n" . "}\n"
        );
        putenv('SEED_PATH=database/seeders');
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($marker);
        @unlink($marker);
    }

    public function testGlobalSeederStillWorksWithoutSeedPath(): void
    {
        $marker = sys_get_temp_dir() . '/seed_ns_marker_d.txt';
        @unlink($marker);
        file_put_contents(
            $this->basePath . '/database/seeds/Dev2GlobalSeeder.php',
            "<?php\nclass Dev2GlobalSeeder {\n    public function run(): void {\n        file_put_contents('" .
                $marker . "', 'ok');\n    }\n}\n"
        );
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['Dev2GlobalSeeder']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($marker);
        @unlink($marker);
    }

    public function testMissingNamespacedSeederReturnsOne(): void
    {
        putenv('SEED_PATH=database/seeders');
        $cmd = new SeedCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['Database\\Seeders\\NoSuchSeeder']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }
}
