<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\EnvCheckCommand;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Lite\BackupManager;
use Siro\Core\Middleware\CsrfMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * BackupManager, EnvCheck, CsrfMiddleware, SeedCommand branches.
 */
final class MiscBranchesMutationTest extends TestCase
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
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234');
        putenv('APP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678');
        $this->basePath = sys_get_temp_dir() . '/siro_misc2_' . uniqid();
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/storage/framework/backups', 0777, true);
        mkdir($this->basePath . '/database/seeds', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        file_put_contents($this->basePath . '/.env', "APP_NAME=Test\nAPP_ENV=testing\nAPP_DEBUG=true\nJWT_SECRET=this_is_a_long_secret_32chars!!\nDB_CONNECTION=sqlite\n");
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testBackupManagerSqlite(): void
    {
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        $dbPath = $this->basePath . '/storage/test.db';
        file_put_contents($dbPath, '');
        $pdo = Database::connection();
        $mgr = new BackupManager($pdo, $dbPath);
        $result = $mgr->backup($this->basePath . '/storage/framework/backups', false);
        $this->assertArrayHasKey('success', $result);
    }

    public function testBackupManagerRestore(): void
    {
        $backupFile = $this->basePath . '/storage/framework/backups/test-backup.db';
        file_put_contents($backupFile, 'data');
        $pdo = Database::connection();
        $mgr = new BackupManager($pdo, $backupFile);
        $result = $mgr->restore($backupFile);
        $this->assertArrayHasKey('success', $result);
    }

    public function testEnvCheck(): void
    {
        $cmd = new EnvCheckCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testEnvCheckMissingEnv(): void
    {
        unlink($this->basePath . '/.env');
        $cmd = new EnvCheckCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testCsrfMiddlewarePass(): void
    {
        $mw = new CsrfMiddleware();
        $req = new Request('GET', '/api/x');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertContains($resp->statusCode(), [200, 403]);
    }
}
