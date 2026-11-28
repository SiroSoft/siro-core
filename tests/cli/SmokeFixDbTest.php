<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Console;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Logger;

/**
 * Regression tests for the database-lane CLI smoke fixes (27/11/2026):
 *
 *  - DB-M2: db:why / db:explain --query with broken SQL must fail loudly
 *    (exit 1 + visible SQL error) instead of exit 0 "No issues detected".
 *  - DB-M3: db:restore <relative path> resolves against the project root
 *    first, falling back to the process working directory.
 *  - S-07: make:openapi must read real routes even when routes/schedule.php
 *    uses a bare $schedule (App::loadRoutes defines it now).
 *
 * DB-M4 (usage strings for migrate / migrate:fresh / db:seed) lives in the
 * Console.php registry, which this lane must not edit — so it has no test
 * here; the registry edits are recorded in the lane REPORT for the
 * coordinator to apply at merge time.
 */
final class SmokeFixDbTest extends TestCase
{
    private Console $console;
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        Env::reset();
        Database::purgeAll();
        Cache::reset();
        Logger::reset();

        $this->tempDir = sys_get_temp_dir() . '/siro_smoke_fix_db_' . bin2hex(random_bytes(4));
        $this->dbPath = $this->tempDir . '/storage/test.db';
        $dirs = ['storage/framework', 'storage/logs', 'storage/backups', 'routes',
            'config', 'database/migrations', 'database/seeds', 'public'];
        foreach ($dirs as $dir) {
            mkdir($this->tempDir . '/' . $dir, 0777, true);
        }

        file_put_contents(
            $this->tempDir . '/.env',
            "APP_ENV=testing\nAPP_DEBUG=true\n"
            . "APP_KEY=testing_app_key_for_hmac_32chars!!\n"
            . "DB_CONNECTION=sqlite\nDB_DATABASE=" . $this->dbPath . "\n",
        );
        file_put_contents(
            $this->tempDir . '/config/database.php',
            '<?php return ["driver" => "sqlite", "database" => '
            . var_export($this->dbPath, true) . '];',
        );
        file_put_contents(
            $this->tempDir . '/config/app.php',
            '<?php return ["name" => "SiroSmokeFixDb", "env" => "testing"];',
        );

        // Real HTTP routes (what make:openapi must find after the S-07 fix).
        file_put_contents(
            $this->tempDir . '/routes/api.php',
            "<?php\n\$router->get('/api/health', 'HealthController@index');\n"
            . "\$router->get('/api/users', 'UserController@index');\n",
        );
        file_put_contents($this->tempDir . '/routes/web.php', "<?php\n\ndeclare(strict_types=1);\n");
        // Skeleton-identical schedule file: bare $schedule, only a @var docblock.
        file_put_contents(
            $this->tempDir . '/routes/schedule.php',
            "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/** @var \\Siro\\Core\\Schedule \$schedule */\n"
            . "\$schedule->command('queue:work')->everyMinute();\n\n"
            . "\$schedule->command('log:cleanup --days=30')->daily();\n",
        );

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
        $pdo = null;

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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Run a console command capturing stdout; returns [exit code, output]. */
    /** @param list<string> $argv
     * @return array{0: int, 1: string} */
    private function runCommand(array $argv): array
    {
        ob_start();
        try {
            $exitCode = $this->console->run($argv);
        } finally {
            $output = (string) ob_get_clean();
        }
        return [$exitCode, $output];
    }

    private function userCount(): int
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $pdo = null;
        return $count;
    }

    private function deleteOneUser(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('DELETE FROM users WHERE id = 2');
        $pdo = null;
    }

    // ==================== DB-M2 ====================

    public function testDbWhyBadTableExitsNonZeroWithVisibleError(): void
    {
        [$exitCode, $output] = $this->runCommand(['siro', 'db:why', '--query=SELECT * FROM no_such_table_xyz']);

        $this->assertSame(1, $exitCode, 'db:why with a query on a missing table must exit 1');
        $this->assertStringContainsString('EXPLAIN failed', $output);
        $this->assertStringContainsString('no such table: no_such_table_xyz', $output);
        $this->assertStringNotContainsString(
            'No issues detected',
            $output,
            'A broken query must never be reported as fine',
        );
    }

    public function testDbWhySyntaxErrorExitsNonZero(): void
    {
        [$exitCode, $output] = $this->runCommand(['siro', 'db:why', '--query=SELEC * FROM users']);

        $this->assertSame(1, $exitCode, 'db:why with a syntax error must exit 1');
        $this->assertStringContainsString('EXPLAIN failed', $output);
        $this->assertStringNotContainsString('No issues detected', $output);
    }

    public function testDbWhyValidQueryStillExitsZero(): void
    {
        [$exitCode, $output] = $this->runCommand(['siro', 'db:why', '--query=SELECT * FROM users']);

        $this->assertSame(0, $exitCode, 'db:why with a valid query must still exit 0');
        $this->assertStringContainsString('SCAN users', $output);
        $this->assertStringNotContainsString('EXPLAIN failed', $output);
    }

    public function testDbExplainForwardsBadQueryExitCode(): void
    {
        [$exitCode, $output] = $this->runCommand(['siro', 'db:explain', '--query=SELECT * FROM no_such_table_xyz']);

        $this->assertSame(1, $exitCode, 'db:explain must forward the db:why failure exit code');
        $this->assertStringContainsString('EXPLAIN failed', $output);
        $this->assertStringNotContainsString('No issues detected', $output);
    }

    // ==================== DB-M3 ====================

    public function testDbRestoreRelativePathResolvedAgainstProjectRoot(): void
    {
        // The process CWD is the repo worktree, NOT the temp project —
        // that is exactly the situation that used to report "not found".
        $this->assertNotSame($this->tempDir, (string) getcwd());
        $this->assertSame(2, $this->userCount());

        copy($this->dbPath, $this->tempDir . '/storage/backups/backup-test.db');
        $this->deleteOneUser();
        $this->assertSame(1, $this->userCount());

        [$exitCode, $output] = $this->runCommand(['siro', 'db:restore', 'storage/backups/backup-test.db', '--force']);

        $this->assertSame(0, $exitCode, 'db:restore with a project-relative path must exit 0: ' . $output);
        $this->assertSame(2, $this->userCount(), 'Restore must bring the deleted row back');
    }

    public function testDbRestoreRelativeMissingPathStillFails(): void
    {
        [$exitCode, $output] = $this->runCommand(['siro', 'db:restore', 'storage/backups/nope.db', '--force']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Backup file not found', $output);
    }

    public function testDbRestoreAbsolutePathStillWorks(): void
    {
        copy($this->dbPath, $this->tempDir . '/storage/backups/backup-test.db');
        $this->deleteOneUser();
        $this->assertSame(1, $this->userCount());

        $absoluteBackup = $this->tempDir . '/storage/backups/backup-test.db';
        [$exitCode] = $this->runCommand(['siro', 'db:restore', $absoluteBackup, '--force']);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $this->userCount());
    }

    // ==================== S-07 ====================

    public function testMakeOpenApiReadsRealRoutesDespiteScheduleFile(): void
    {
        $specFile = $this->tempDir . '/openapi.json';
        [$exitCode, $output] = $this->runCommand(['siro', 'make:openapi', '--output=' . $specFile, '--force']);

        $this->assertSame(0, $exitCode, 'make:openapi must exit 0: ' . $output);
        $this->assertStringNotContainsString('Could not read routes from app', $output);
        $this->assertStringNotContainsString('fallback mode', $output);
        $this->assertStringNotContainsString('Undefined variable $schedule', $output);
        $this->assertFileExists($specFile);

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents($specFile), true);
        $this->assertIsArray($spec);
        /** @var array<string, mixed> $paths */
        $paths = $spec['paths'] ?? [];
        $this->assertArrayHasKey('/api/health', $paths, 'Spec must contain the real /api/health route');
        $this->assertArrayHasKey('/api/users', $paths, 'Spec must contain the real /api/users route');
        $this->assertArrayNotHasKey('/api/user', $paths, 'Spec must not contain the phantom singular /api/user');
        $this->assertArrayNotHasKey('/api/category', $paths, 'Spec must not contain phantom fallback routes');
    }

    public function testScheduleListStillWorks(): void
    {
        [$exitCode, $output] = $this->runCommand(['siro', 'schedule:list']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('queue:work', $output);
        $this->assertStringContainsString('log:cleanup --days=30', $output);
    }

    // ==================== DB-M4 guard (behaviour, not usage text) ====================

    public function testMigrateForceRecordStillOnlyWarns(): void
    {
        // --force-record was removed in v1.4.0 on purpose (backwards compat):
        // it must keep warning and let migrate run, never change behaviour.
        [$exitCode, $output] = $this->runCommand(['siro', 'migrate', '--force-record']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('was removed in v1.4.0', $output);
        $this->assertStringContainsString('Nothing to migrate.', $output);
    }
}
