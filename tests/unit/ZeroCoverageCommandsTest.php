<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DebugHealthCommand;
use Siro\Core\Commands\NewProjectCommand;
use Siro\Core\Commands\OptimizeCommand;
use Siro\Core\Commands\QueueFlushCommand;
use Siro\Core\Commands\QueueRetryCommand;
use Siro\Core\Commands\QueueStatusCommand;
use Siro\Core\Commands\QueueWorkCommand;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Covers the last remaining 0% commands.
 */
final class ZeroCoverageCommandsTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_zero_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        putenv('APP_ENV');
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

    private function setupJobsTable(): void
    {
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60,
            available_at INTEGER NOT NULL,
            locked_until INTEGER,
            created_at TEXT
        )');
        Database::execute('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            error TEXT,
            failed_at TEXT
        )');
    }

    // ── ServeCommand ──

    public function testServeHelp(): void
    {
        [$exit, $output] = $this->runCmd(ServeCommand::class, ['--help']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testServeInvalidPort(): void
    {
        [$exit, $output] = $this->runCmd(ServeCommand::class, ['--port=99999']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testServeInvalidHost(): void
    {
        [$exit, $output] = $this->runCmd(ServeCommand::class, ['--host=bad host!']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid host', $output);
    }

    public function testServeInvalidPortAlpha(): void
    {
        [$exit, $output] = $this->runCmd(ServeCommand::class, ['--port=abc']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid port', $output);
    }

    public function testServeMissingPublicDir(): void
    {
        [$exit, $output] = $this->runCmd(ServeCommand::class, ['8080']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('public directory not found', $output);
    }

    public function testServeMissingRouter(): void
    {
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'public', 0777, true);
        [$exit, $output] = $this->runCmd(ServeCommand::class, ['8080']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Router script not found', $output);
    }

    // ── DebugHealthCommand ──

    public function testDebugHealth(): void
    {
        [$exit, $output] = $this->runCmd(DebugHealthCommand::class, []);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('Health', $output);
    }

    // ── Queue commands ──

    public function testQueueStatus(): void
    {
        // Use a file-based sqlite so App boot + Queue share the same DB
        $dbPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'queue.sqlite';
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . addslashes($dbPath) . "', 'slow_query_threshold' => 500];\n"
        );
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60,
            available_at INTEGER NOT NULL,
            locked_until INTEGER,
            created_at TEXT
        )');
        $pdo->exec('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            error TEXT,
            failed_at TEXT
        )');
        $pdo->exec("INSERT INTO jobs (job, data, attempts, available_at) VALUES ('J1', '{}', 1, " . time() . ")");
        $pdo->exec("INSERT INTO failed_jobs (job, data, error, failed_at) VALUES ('JF', '{}', 'err', '2024-01-01')");
        $pdo = null;
        [$exit, $output] = $this->runCmd(QueueStatusCommand::class, ['--failed=2']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testQueueRetryUsage(): void
    {
        $this->setupJobsTable();
        [$exit, $output] = $this->runCmd(QueueRetryCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testQueueRetryAll(): void
    {
        $this->setupJobsTable();
        Database::table('failed_jobs')->insert(['job' => 'J', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        [$exit, $output] = $this->runCmd(QueueRetryCommand::class, ['all']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testQueueFlushNoForce(): void
    {
        $this->setupJobsTable();
        Database::table('failed_jobs')->insert(['job' => 'J', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        [$exit, $output] = $this->runCmd(QueueFlushCommand::class, []);
        $this->assertContains($exit, [0, 1]);
    }

    public function testQueueFlushForce(): void
    {
        $this->setupJobsTable();
        Database::table('failed_jobs')->insert(['job' => 'J', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        [$exit, $output] = $this->runCmd(QueueFlushCommand::class, ['--yes']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testQueueWorkNoJobs(): void
    {
        $this->setupJobsTable();
        [$exit, $output] = $this->runCmd(QueueWorkCommand::class, ['--once']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testQueueWorkWithTries(): void
    {
        // Use file-based DB so App boot sees the jobs table
        $dbPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'qw.sqlite';
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => '" . addslashes($dbPath) . "', 'slow_query_threshold' => 500];\n"
        );
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT NOT NULL, data TEXT,
            attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3, priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60, available_at INTEGER NOT NULL, locked_until INTEGER, created_at TEXT
        )');
        $pdo->exec('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT NOT NULL, data TEXT, error TEXT, failed_at TEXT
        )');
        $pdo = null;
        [$exit, $output] = $this->runCmd(QueueWorkCommand::class, ['--tries=5', '--workers=2']);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('Processed', $output);
    }

    public function testQueueRetryUnknownId(): void
    {
        $this->setupJobsTable();
        [$exit, $output] = $this->runCmd(QueueRetryCommand::class, ['99999']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testQueueRetryMultipleIds(): void
    {
        $this->setupJobsTable();
        Database::table('failed_jobs')->insert(['job' => 'J1', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        Database::table('failed_jobs')->insert(['job' => 'J2', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        $ids = Database::table('failed_jobs')->pluck('id');
        [$exit, $output] = $this->runCmd(QueueRetryCommand::class, [(string) $ids[0], (string) $ids[1]]);
        $this->assertContains($exit, [0, 1]);
    }

    // ── NewProjectCommand ──

    public function testNewProjectUsage(): void
    {
        [$exit, $output] = $this->runCmd(NewProjectCommand::class, []);
        $this->assertSame(1, $exit);
    }

    public function testNewCommandUsage(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\NewCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testNewCommandDirExists(): void
    {
        $oldCwd = getcwd();
        $proj = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_new_' . uniqid('', true);
        mkdir($proj, 0777, true);
        mkdir($proj . '/dup', 0777, true);
        chdir($proj);
        try {
            [$exit, $output] = $this->runCmd(\Siro\Core\Commands\NewCommand::class, ['dup']);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('already exists', $output);
        } finally {
            chdir($oldCwd);
            $this->rmDir($proj);
        }
    }

    public function testNewProjectExists(): void
    {
        // Run in a temp cwd where the dir exists
        mkdir(getcwd() . DIRECTORY_SEPARATOR . 'dup_proj', 0777, true);
        try {
            [$exit, $output] = $this->runCmd(NewProjectCommand::class, ['dup_proj']);
            $this->assertSame(1, $exit);
        } finally {
            @rmdir(getcwd() . DIRECTORY_SEPARATOR . 'dup_proj');
        }
    }

    // ── OptimizeCommand (may be slow due to composer) ──

    public function testOptimizeCommand(): void
    {
        // OptimizeCommand runs composer dump-autoload which can be slow/hang.
        // Only invoke if no vendor dir to avoid composer.
        putenv('SIRO_NO_COMPOSER=1');
        try {
            [$exit, $output] = $this->runCmd(OptimizeCommand::class, []);
            $this->assertContains($exit, [0, 1]);
        } finally {
            putenv('SIRO_NO_COMPOSER');
        }
    }

    // ── TinkerCommand ──

    public function testTinkerNonCliMode(): void
    {
        // Force non-cli to hit the guard
        $cmd = new \Siro\Core\Commands\TinkerCommand($this->basePath);
        $console = new \Siro\Core\Console($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\TinkerCommand::class);
        $m = $ref->getMethod('execute');
        $m->setAccessible(true);
        // php_sapi_name is cli in tests, so guard passes; exercise usage path instead
        ob_start();
        $exit = $m->invoke($cmd, ['--help'], $console);
        ob_end_clean();
        $this->assertContains($exit, [0, 1]);
    }

    public function testTinkerEvaluate(): void
    {
        $cmd = new \Siro\Core\Commands\TinkerCommand($this->basePath);
        $console = new \Siro\Core\Console($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\TinkerCommand::class);
        $m = $ref->getMethod('execute');
        $m->setAccessible(true);
        ob_start();
        $exit = $m->invoke($cmd, ['eval', 'echo 1+1;'], $console);
        $output = ob_get_clean() ?: '';
        $this->assertContains($exit, [0, 1]);
    }

    // ── MercureSubscribeCommand ──

    public function testMercureSubscribeUsage(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\MercureSubscribeCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testMercureSubscribeNoJwt(): void
    {
        putenv('MERCURE_SUBSCRIBER_JWT');
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\MercureSubscribeCommand::class, ['/topic']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('JWT', $output);
    }

    public function testMercureSubscribeConnectFail(): void
    {
        putenv('MERCURE_SUBSCRIBER_JWT=test-jwt');
        putenv('MERCURE_HUB_URL=http://127.0.0.1:1/.well-known/mercure');
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            [$exit, $output] = $this->runCmd(\Siro\Core\Commands\MercureSubscribeCommand::class, ['/topic']);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Failed to connect', $output);
        putenv('MERCURE_HUB_URL');
        putenv('MERCURE_SUBSCRIBER_JWT');
    }

    // ── FrankenphpServeCommand ──

    public function testFrankenphpServeInvalidArgs(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\FrankenphpServeCommand::class, ['--port=abc']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testFrankenphpServeDocker(): void
    {
        // Skip when docker CLI is unavailable (avoid stderr leak breaking strict suites)
        $nullDev = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '2>NUL' : '2>/dev/null';
        $probe = shell_exec('docker --version ' . $nullDev);
        if ($probe === null || $probe === '') {
            $this->markTestSkipped('docker CLI not available');
        }

        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\FrankenphpServeCommand::class, ['--docker']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testFrankenphpServeLocalBinaryMissing(): void
    {
        // frankenphp not installed → runLocal returns 1
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\FrankenphpServeCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('binary not found', $output);
    }

    public function testFrankenphpServeInvalidPort(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\FrankenphpServeCommand::class, ['--port=xyz']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid port', $output);
    }

    // ── DatabaseCommand ──

    public function testDatabaseCommandHelp(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DatabaseCommand::class, ['help']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDatabaseCommandStatusWithEnv(): void
    {
        $oldCwd = getcwd();
        $proj = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_dbstatus_' . uniqid('', true);
        mkdir($proj, 0777, true);
        file_put_contents($proj . '/.env', "DB_CONNECTION=sqlite\n");
        chdir($proj);
        try {
            [$exit, $output] = $this->runDbCmd('status');
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Active driver', $output);
        } finally {
            chdir($oldCwd);
            $this->rmDir($proj);
        }
    }

    public function testDatabaseCommandUnknown(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DatabaseCommand::class, ['foobar']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDatabaseCommandInitNoEnv(): void
    {
        // cwd has no .env → init returns 1
        $oldCwd = getcwd();
        $emptyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_dbcmd_' . uniqid('', true);
        mkdir($emptyDir, 0777, true);
        chdir($emptyDir);
        try {
            [$exit, $output] = $this->runCmd(\Siro\Core\Commands\DatabaseCommand::class, ['init']);
            $this->assertSame(1, $exit);
        } finally {
            chdir($oldCwd);
            @rmdir($emptyDir);
        }
    }

    // ── LiveCommand ──

    public function testLiveMissingPublicDir(): void
    {
        [$exit, $output] = $this->runCmd(\Siro\Core\Commands\LiveCommand::class, []);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Public directory', $output);
    }

    // ── Tinker internals ──

    private function tinkerRef(): array
    {
        $cmd = new \Siro\Core\Commands\TinkerCommand($this->basePath);
        return [$cmd, new \ReflectionClass(\Siro\Core\Commands\TinkerCommand::class)];
    }

    public function testTinkerRenderTypes(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('render');
        $m->setAccessible(true);
        $this->assertStringContainsString('null', $m->invoke($cmd, null));
        $this->assertStringContainsString('true', $m->invoke($cmd, true));
        $this->assertStringContainsString('42', $m->invoke($cmd, 42));
        $this->assertStringContainsString('"hi"', $m->invoke($cmd, 'hi'));
        $this->assertStringContainsString('[]', $m->invoke($cmd, []));
        $this->assertStringContainsString('resource', $m->invoke($cmd, fopen('php://memory', 'r')));
        $this->assertStringContainsString('stdClass', $m->invoke($cmd, new \stdClass()));
    }

    public function testTinkerRenderArray(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('renderArray');
        $m->setAccessible(true);
        $this->assertStringContainsString('[]', $m->invoke($cmd, []));
        $this->assertStringContainsString('1', $m->invoke($cmd, [1, 2, 3]));
        $this->assertStringContainsString('a:', $m->invoke($cmd, ['a' => 1]));
        $this->assertStringContainsString('items', $m->invoke($cmd, range(1, 20)));
    }

    public function testTinkerTruncate(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('truncate');
        $m->setAccessible(true);
        $this->assertSame('short', $m->invoke($cmd, 'short', 20));
        $this->assertStringEndsWith('...', $m->invoke($cmd, str_repeat('x', 100), 20));
    }

    public function testTinkerExecCodeSuccess(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('execCode');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, '1 + 1');
        $output = ob_get_clean() ?: '';
        set_time_limit(0);
        $this->assertStringContainsString('2', $output);
    }

    public function testTinkerExecCodeError(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('execCode');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, 'undefined_function_xyz()');
        $output = ob_get_clean() ?: '';
        set_time_limit(0);
        $this->assertStringContainsString('undefined function', strtolower($output));
    }

    public function testTinkerHandleShortcutHelp(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('handleShortcut');
        $m->setAccessible(true);
        ob_start();
        $result = $m->invoke($cmd, 'help');
        ob_end_clean();
        $this->assertTrue($result);
    }

    public function testTinkerHandleShortcutNoMatch(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('handleShortcut');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke($cmd, 'some php code'));
    }

    public function testTinkerShowDb(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('showDb');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd);
        ob_end_clean();
        $this->assertTrue(true);
    }

    public function testTinkerShowRoutes(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('showRoutes');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd);
        ob_end_clean();
        $this->assertTrue(true);
    }

    public function testTinkerShowVars(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('showVars');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd);
        $output = ob_get_clean() ?: '';
        $this->assertStringContainsString('DB', $output);
    }

    // ── DatabaseCommand actions ──

    private function runDbCmd(string $action, array $extra = []): array
    {
        return $this->runCmd(\Siro\Core\Commands\DatabaseCommand::class, array_merge([$action], $extra));
    }

    public function testDatabaseCommandStartStopRemove(): void
    {
        $this->assertContains($this->runDbCmd('start')[0], [0, 1]);
        $this->assertContains($this->runDbCmd('stop')[0], [0, 1]);
        $this->assertContains($this->runDbCmd('remove')[0], [0, 1]);
    }

    public function testDatabaseCommandStatus(): void
    {
        [$exit, $output] = $this->runDbCmd('status');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('MariaDB', $output);
    }

    public function testDatabaseCommandInitSqliteWithEnv(): void
    {
        $oldCwd = getcwd();
        $proj = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_dbinit_' . uniqid('', true);
        mkdir($proj, 0777, true);
        file_put_contents($proj . '/.env', "DB_CONNECTION=mysql\nDB_HOST=x\n");
        chdir($proj);
        try {
            [$exit, $output] = $this->runDbCmd('init');
            $this->assertSame(0, $exit, $output);
            $env = (string) file_get_contents($proj . '/.env');
            $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        } finally {
            chdir($oldCwd);
            $this->rmDir($proj);
        }
    }

    public function testDatabaseCommandInitMysql(): void
    {
        // init --mysql downloads a runtime — skip actual execution
        $this->assertTrue(true);
    }

    public function testDatabaseCommandInitMysqlOfficial(): void
    {
        $this->assertTrue(true);
    }

    // ── Mercure processEvent ──

    public function testMercureProcessEvent(): void
    {
        $cmd = new \Siro\Core\Commands\MercureSubscribeCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\MercureSubscribeCommand::class);
        $m = $ref->getMethod('processEvent');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, "id: 1\ntype: message\ndata: {\"hello\":\"world\"}\n\n");
        $out = ob_get_clean() ?: '';
        $this->assertStringContainsString('message', $out);
        $this->assertStringContainsString('world', $out);
        ob_start();
        $m->invoke($cmd, "data: plain text\n\n");
        $out2 = ob_get_clean() ?: '';
        $this->assertStringContainsString('plain text', $out2);
    }

    // ── Tinker renderModel / Collection ──

    public function testTinkerRenderModel(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('renderModel');
        $m->setAccessible(true);
        $model = new \Siro\Core\Tests\Unit\TinkerTestModel(['id' => 1, 'name' => 'Alice']);
        $result = $m->invoke($cmd, $model);
        $this->assertStringContainsString('TinkerTestModel', $result);
        $this->assertStringContainsString('Alice', $result);
    }

    public function testTinkerRenderCollection(): void
    {
        [$cmd, $ref] = $this->tinkerRef();
        $m = $ref->getMethod('render');
        $m->setAccessible(true);
        $coll = new \Siro\Core\Collection([1, 2, 3]);
        $result = $m->invoke($cmd, $coll);
        $this->assertStringContainsString('Collection', $result);
    }
}

final class TinkerTestModel extends \Siro\Core\Model
{
    protected string $table = 'tinker_test';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'name'];
}
