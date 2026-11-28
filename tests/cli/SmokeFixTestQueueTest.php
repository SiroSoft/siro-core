<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\ApiTestCommand;
use Siro\Core\Commands\QueueWorkCommand;
use Siro\Core\Commands\TestCommand;
use Siro\Core\Commands\TestRunCommand;
use Siro\Core\Console;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Logger;

/**
 * Regression tests for the CLI smoke-test fixes (lane TEST+QUEUE,
 * 2026-11-27): api:test exit codes/collections/login/field syntax/
 * history/signals, test:run coverage + cwd, queue:work --tries/--sleep/
 * daemon/signals.
 *
 * Temp projects live under sys_get_temp_dir() and are removed in
 * tearDown. Subprocess tests (daemon/watch/webhook signal handling)
 * spawn PHP_BINARY with a small Console runner script so the real
 * Console signal handler is in play, exactly like the smoke harness.
 */
final class SmokeFixTestQueueTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];
    private string $worktree;

    protected function setUp(): void
    {
        $this->worktree = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        Env::reset();
        Database::purgeAll();
        Cache::reset();
        Logger::reset();
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    // ==================== helpers ====================

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeProjectBase(string $dir, string $dbDatabase): void
    {
        foreach (['storage/framework', 'storage/logs/traces', 'storage/cache', 'storage/app', 'routes', 'config', 'app'] as $d) {
            mkdir($dir . '/' . $d, 0777, true);
        }
        file_put_contents(
            $dir . '/.env',
            "APP_NAME=\"SiroSmokeFix\"\nAPP_ENV=testing\nAPP_DEBUG=true\n"
            . "APP_KEY=testing_app_key_for_hmac_32chars!!\nJWT_SECRET=test_jwt_secret_32_chars_minimum!!\n"
            . "DB_CONNECTION=sqlite\nDB_DATABASE={$dbDatabase}\n"
            . "THROTTLE_FALLBACK=disabled\nCORS_ALLOWED_ORIGINS=*\n"
        );
        file_put_contents(
            $dir . '/config/database.php',
            '<?php return ["driver" => "sqlite", "database" => ' . var_export($dbDatabase, true) . ', "charset" => "utf8mb4", "slow_query_threshold" => 500];'
        );
        file_put_contents($dir . '/config/app.php', '<?php return ["name" => "SiroSmokeFix", "env" => "testing"];');
        file_put_contents($dir . '/routes/schedule.php', "<?php\n\ndeclare(strict_types=1);\n");
    }

    /** Minimal API project: /health/live (200), /health (401), /echo, stub login. */
    private function makeApiProject(): string
    {
        $dir = $this->makeTempDir('siro_smoke_api');
        $this->writeProjectBase($dir, ':memory:');
        file_put_contents($dir . '/routes/api.php', <<<'PHP'
<?php

declare(strict_types=1);

use Siro\Core\Request;
use Siro\Core\Response;

/** @var \Siro\Core\App $app */

$app->router->get('/health/live', function (): array {
    return ['success' => true, 'message' => 'OK', 'data' => ['status' => 'alive']];
});

$app->router->get('/health', function (): Response {
    return Response::error('Unauthorized', 401);
});

$app->router->post('/echo', function (Request $req): array {
    return ['success' => true, 'received' => $req->all()];
});

$app->router->post('/api/auth/login', function (Request $req): mixed {
    $email = (string) ($req->input('email') ?? '');
    if ($email === '') {
        return Response::error('Validation failed', 422, ['email' => ['Email is required']]);
    }
    return ['success' => true, 'message' => 'Login successful', 'data' => ['token' => 'test-token-1234567890abcdef']];
});
PHP);
        return $dir;
    }

    /** Run ApiTestCommand in-process, capturing echo output. */
    /** @return array{0: int, 1: string} */
    private function runApi(string $project, array $args): array
    {
        $cmd = new ApiTestCommand($project, fn (string $q): string => '');
        ob_start();
        try {
            $exit = $cmd->run($args);
        } finally {
            $output = (string) ob_get_clean();
        }
        return [$exit, $output];
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $path): array
    {
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($raw) ? $raw : [];
    }

    // ==================== S-02 / TQ-M1, M5, M6 ====================

    public function testApiTestExitCodeReflectsHttpStatus(): void
    {
        $project = $this->makeApiProject();

        [$exit401, $out401] = $this->runApi($project, ['GET', '/health']);
        $this->assertStringContainsString('Status: 401', $out401);
        $this->assertSame(1, $exit401, 'HTTP 401 must exit 1 (was: always 0)');

        [$exit200] = $this->runApi($project, ['GET', '/health/live']);
        $this->assertSame(0, $exit200, 'HTTP 200 must exit 0');

        [$exit404, $out404] = $this->runApi($project, ['GET', '/no-such-route-xyz']);
        $this->assertStringContainsString('Status: 404', $out404);
        $this->assertSame(1, $exit404, 'HTTP 404 must exit 1 (was: always 0)');
    }

    public function testApiTestLoopShowsRealStatus(): void
    {
        $project = $this->makeApiProject();
        [$exit, $out] = $this->runApi($project, ['GET', '/health/live', '--loop=2']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Request 1/2: 200', $out, 'loop must show HTTP 200, not 0');
        $this->assertStringContainsString('Request 2/2: 200', $out);
    }

    public function testApiTestCorsSummaryShowsRealStatuses(): void
    {
        $project = $this->makeApiProject();
        [$exit, $out] = $this->runApi($project, ['GET', '/health/live', '--cors']);
        $this->assertSame(0, $exit);
        foreach (['Preflight \\(OPTIONS\\)', 'With Origin', 'Without Origin'] as $label) {
            $this->assertMatchesRegularExpression(
                '/' . $label . ':\\s+([1-9][0-9]{2})/',
                $out,
                "CORS summary line '{$label}' must show a real HTTP status, not 0"
            );
        }
    }

    // ==================== S-03 / TQ-M4 ====================

    public function testApiTestCollectionSaveListRun(): void
    {
        $project = $this->makeApiProject();

        [$saveExit] = $this->runApi($project, ['GET', '/health/live', '--collection-save=smoke1']);
        $this->assertSame(0, $saveExit);
        $collections = $this->readJsonFile($project . '/storage/api-test-collections.json');
        $this->assertArrayHasKey('smoke1', $collections);
        $requests = $collections['smoke1']['requests'] ?? null;
        $this->assertIsArray($requests, 'saved collection must actually store the request');
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('/health/live', $requests[0]['path']);

        [$listExit, $listOut] = $this->runApi($project, ['--collection-list']);
        $this->assertSame(0, $listExit);
        $this->assertStringContainsString('smoke1', $listOut);

        [$runExit, $runOut] = $this->runApi($project, ['--collection=smoke1']);
        $this->assertSame(0, $runExit);
        $this->assertStringContainsString('1 passed, 0 failed', $runOut);
    }

    // ==================== S-09 / TQ-M16 ====================

    public function testApiTestBareLoginUsesDefaultRole(): void
    {
        $project = $this->makeApiProject();
        [$exit, $out] = $this->runApi($project, ['GET', '/health/live', '--login']);
        $this->assertSame(0, $exit, 'bare --login must succeed with role default: ' . $out);
        $this->assertStringContainsString('Login successful', $out);
        $tokens = $this->readJsonFile($project . '/storage/api-test-auth.json');
        $this->assertArrayHasKey('default', $tokens, 'token must be saved under role default');
    }

    // ==================== TQ-M2 ====================

    public function testApiTestFieldColonValueParsed(): void
    {
        $project = $this->makeApiProject();
        [$exit, $out] = $this->runApi($project, ['POST', '/echo', 'name:John', 'age:30']);
        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Enter fields', $out, 'field:value args must not trigger the prompt');
        $history = $this->readJsonFile($project . '/storage/api-test-history.json');
        $last = end($history);
        $this->assertIsArray($last);
        $this->assertSame(['name' => 'John', 'age' => '30'], $last['fields'] ?? null);

        // key=value still works.
        [$exit2] = $this->runApi($project, ['POST', '/echo', 'name=Jane']);
        $this->assertSame(0, $exit2);
        $history = $this->readJsonFile($project . '/storage/api-test-history.json');
        $last = end($history);
        $this->assertSame(['name' => 'Jane'], $last['fields'] ?? null);
    }

    // ==================== TQ-M3 ====================

    public function testApiTestHistoryEqualsForm(): void
    {
        $project = $this->makeApiProject();
        $this->runApi($project, ['GET', '/health/live']);
        [$exit, $out] = $this->runApi($project, ['--history=2']);
        $this->assertSame(0, $exit, '--history=2 must not fail with "Method and path are required"');
        $this->assertStringContainsString('/health/live', $out);
        $this->assertStringContainsString('Total: 1 requests', $out);
    }

    // ==================== TQ-M10 (in-file usage) ====================

    public function testApiTestHelpListsFlags(): void
    {
        $project = $this->makeApiProject();
        [$exit, $out] = $this->runApi($project, []);
        $this->assertSame(0, $exit);
        foreach (['--loop=N', '--login', '--cors', '--webhook', '--body=', '--json=<json>'] as $flag) {
            $this->assertStringContainsString($flag, $out, "api:test help must list {$flag}");
        }
    }

    // ==================== S-10 / TQ-M9 (test commands) ====================

    /** Temp project with a real phpunit binary + phpunit.xml WITH a <coverage> block. */
    private function makePhpunitProject(): string
    {
        $dir = $this->makeTempDir('siro_smoke_phpunit');
        foreach (['tests/unit', 'vendor/bin', 'storage'] as $d) {
            mkdir($dir . '/' . $d, 0777, true);
        }
        file_put_contents($dir . '/tests/unit/SampleTest.php', <<<'PHP'
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SampleTest extends TestCase
{
    public function testSamplePasses(): void
    {
        $this->assertTrue(true);
    }
}
PHP);
        file_put_contents($dir . '/tests/bootstrap.php', "<?php\nrequire_once __DIR__ . '/../vendor/autoload.php';\n");
        file_put_contents(
            $dir . '/vendor/autoload.php',
            "<?php\nrequire '" . $this->worktree . "/vendor/autoload.php';\n"
        );
        symlink($this->worktree . '/vendor/bin/phpunit', $dir . '/vendor/bin/phpunit');
        file_put_contents($dir . '/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="false" cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <report>
            <html outputDirectory="coverage/html"/>
            <text outputFile="coverage/text.txt"/>
        </report>
    </coverage>
</phpunit>
XML);
        return $dir;
    }

    /** Run a command that chdirs, always restoring the caller's cwd. */
    private function runWithCwdRestore(callable $fn): int
    {
        $cwd = getcwd();
        try {
            return $fn();
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }

    public function testTestRunNoCoverageByDefault(): void
    {
        $project = $this->makePhpunitProject();
        $exit = $this->runWithCwdRestore(
            fn (): int => (new TestRunCommand($project))->run(['--filter=testSamplePasses'])
        );
        $this->assertSame(0, $exit, 'test:run must run tests by default even with a <coverage> block and no driver');
    }

    public function testTestRunExplicitNoCoveragePassthrough(): void
    {
        $project = $this->makePhpunitProject();
        $exit = $this->runWithCwdRestore(
            fn (): int => (new TestRunCommand($project))->run(['--filter=testSamplePasses', '--no-coverage'])
        );
        $this->assertSame(0, $exit, '--no-coverage must reach PHPUnit, not be silently dropped');
    }

    public function testTestAndTestRunWorkFromOtherCwd(): void
    {
        $project = $this->makePhpunitProject();
        $otherCwd = sys_get_temp_dir();

        $exitTest = $this->runWithCwdRestore(function () use ($project, $otherCwd): int {
            chdir($otherCwd);
            return (new TestCommand($project))->run(['--filter=testSamplePasses']);
        });
        $this->assertSame(0, $exitTest, 'test must find phpunit.xml when cwd is not the project root');

        $exitRun = $this->runWithCwdRestore(function () use ($project, $otherCwd): int {
            chdir($otherCwd);
            return (new TestRunCommand($project))->run(['--filter=testSamplePasses']);
        });
        $this->assertSame(0, $exitRun, 'test:run must find phpunit.xml when cwd is not the project root');
    }

    // ==================== TQ-M11 (queue --tries) ====================

    /** Queue project with a FILE sqlite DB + jobs/failed_jobs tables. */
    private function makeQueueProject(): string
    {
        $dir = $this->makeTempDir('siro_smoke_queue');
        $dbFile = $dir . '/storage/test.db';
        $this->writeProjectBase($dir, $dbFile);
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT, data TEXT, '
            . 'attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3, available_at INTEGER, '
            . 'locked_until INTEGER, timeout INTEGER DEFAULT 60, priority INTEGER DEFAULT 0, created_at TEXT)');
        $pdo->exec('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT, data TEXT, error TEXT, failed_at TEXT)');
        $pdo = null;
        return $dir;
    }

    private function insertFailingJob(string $project, int $maxAttempts): void
    {
        $pdo = new \PDO('sqlite:' . $project . '/storage/test.db');
        $stmt = $pdo->prepare('INSERT INTO jobs (job, data, attempts, max_attempts, available_at, created_at) VALUES (?, ?, 0, ?, ?, datetime("now"))');
        // Unregistered class: Queue::work() fails it via the whitelist check.
        $stmt->execute(['App\\Jobs\\NoSuchJobXYZ', '[]', $maxAttempts, time()]);
        $pdo = null;
    }

    /** @return array{jobs: int, failed: int, attempts: int, max: int} */
    private function queueCounts(string $project): array
    {
        $pdo = new \PDO('sqlite:' . $project . '/storage/test.db');
        $jobs = (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
        $failed = (int) $pdo->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn();
        $row = $pdo->query('SELECT attempts, max_attempts FROM jobs LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $pdo = null;
        return [
            'jobs' => $jobs,
            'failed' => $failed,
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max' => (int) ($row['max_attempts'] ?? 0),
        ];
    }

    public function testQueueWorkTriesOverridesMaxAttempts(): void
    {
        $project = $this->makeQueueProject();
        $this->insertFailingJob($project, 1);
        $exit = (new QueueWorkCommand($project))->run(['--tries=5']);
        $this->assertSame(0, $exit);
        $counts = $this->queueCounts($project);
        $this->assertSame(0, $counts['failed'], '--tries=5 must not let a max_attempts=1 job fail after 1 attempt');
        $this->assertSame(1, $counts['jobs']);
        $this->assertSame(1, $counts['attempts']);
        // --tries is a runtime override (Queue::setMaxAttemptsOverride),
        // not a rewrite of the stored row: the stored max_attempts stays
        // 1. The old UPDATE-based implementation rewrote it to 5; the
        // override also covers jobs pushed after the worker started and
        // works for the redis driver too (CliSmallFixesTest).
        $this->assertSame(1, $counts['max']);
    }

    public function testQueueWorkWithoutTriesKeepsRowMaxAttempts(): void
    {
        $project = $this->makeQueueProject();
        $this->insertFailingJob($project, 1);
        $exit = (new QueueWorkCommand($project))->run([]);
        $this->assertSame(0, $exit);
        $counts = $this->queueCounts($project);
        $this->assertSame(1, $counts['failed'], 'control: without --tries the job must fail after its own 1 attempt');
        $this->assertSame(0, $counts['jobs']);
    }

    // ==================== TQ-M12/M13/M8 (signals, subprocess) ====================

    private function writeRunner(string $project): string
    {
        $runner = $project . '/run.php';
        file_put_contents($runner, "<?php\ndeclare(strict_types=1);\n"
            . "\$project = \$argv[1];\ndefine('BASE_PATH', \$project);\n"
            . "require '" . $this->worktree . "/vendor/autoload.php';\n"
            . "\$console = new \\Siro\\Core\\Console(\$project);\n"
            . "exit(\$console->run(array_merge(['siro'], array_slice(\$argv, 2))));\n");
        return $runner;
    }

    /**
     * Start a long-running command via the Console runner, assert it is
     * still running after $settleSeconds, SIGTERM it, and assert it exits
     * 0 within $waitSeconds. Returns the child PIDs seen before SIGTERM.
     *
     * @param list<string> $args
     * @return list<int>
     */
    private function startAssertRunningThenSigterm(
        string $project,
        array $args,
        int $settleSeconds = 2,
        int $waitSeconds = 5,
        ?string $runnerPath = null
    ): array {
        $runner = $runnerPath ?? $this->writeRunner($project);
        $log = $project . '/daemon.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'w'],
        ];
        $proc = proc_open(array_merge([PHP_BINARY, $runner, $project], $args), $descriptors, $pipes);
        $this->assertIsResource($proc, 'failed to start subprocess: ' . implode(' ', $args));
        try {
            sleep($settleSeconds);
            $status = proc_get_status($proc);
            $this->assertTrue($status['running'], 'process must still be running after settle: ' . implode(' ', $args));
            $parentPid = (int) $status['pid'];
            $children = $this->childPids($parentPid);
            proc_terminate($proc, SIGTERM);
            $deadline = time() + $waitSeconds;
            $exitCode = null;
            do {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                usleep(200000);
            } while (time() < $deadline);
            if ($exitCode === null) {
                proc_terminate($proc, SIGKILL);
                $this->fail('process ignored SIGTERM (still running after ' . $waitSeconds . 's): ' . implode(' ', $args));
            }
            $this->assertSame(0, $exitCode, 'process must exit 0 on SIGTERM: ' . implode(' ', $args));
            return $children;
        } finally {
            if (is_resource($proc)) {
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, SIGKILL);
                }
                proc_close($proc);
            }
        }
    }

    /** @return list<int> */
    private function childPids(int $pid): array
    {
        $out = shell_exec('pgrep -P ' . $pid);
        if (!is_string($out) || trim($out) === '') {
            return [];
        }
        return array_map('intval', preg_split('/\s+/', trim($out)) ?: []);
    }

    private function assertPidDead(int $pid): void
    {
        $alive = false;
        if (function_exists('posix_kill')) {
            $alive = posix_kill($pid, 0);
        } else {
            exec('kill -0 ' . $pid . ' 2>/dev/null', $o, $code);
            $alive = $code === 0;
        }
        $this->assertFalse($alive, "PID {$pid} must be dead (no orphan worker)");
    }

    public function testQueueDaemonWorkersOneStaysAliveAndStopsOnSigterm(): void
    {
        $project = $this->makeQueueProject();
        $this->startAssertRunningThenSigterm($project, ['queue:work', '--daemon', '--sleep=1']);
        $this->assertStringContainsString('Daemon stopped.', (string) file_get_contents($project . '/daemon.log'));
    }

    public function testQueueDaemonWorkersTwoStopsChildrenOnSigterm(): void
    {
        $project = $this->makeQueueProject();
        $children = $this->startAssertRunningThenSigterm($project, ['queue:work', '--daemon', '--workers=2', '--sleep=1']);
        $this->assertCount(2, $children, 'daemon --workers=2 must fork exactly 2 children');
        foreach ($children as $child) {
            $this->assertPidDead($child);
        }
    }

    // ==================== workAll() parent (CLI eval §3) ====================

    private function writeWorkAllRunner(string $project): string
    {
        $runner = $project . '/run-workall.php';
        file_put_contents($runner, "<?php\ndeclare(strict_types=1);\n"
            . "\$project = \$argv[1];\ndefine('BASE_PATH', \$project);\n"
            . "require '" . $this->worktree . "/vendor/autoload.php';\n"
            . "\$app = new \\Siro\\Core\\App(\$project);\n\$app->boot();\n"
            . "exit(\\Siro\\Core\\Queue::workAll(2));\n");
        return $runner;
    }

    /**
     * Queue::workAll(2) must stop on SIGTERM like queue:work --daemon
     * does. Before the fix the parent blocked in pcntl_waitpid(), which
     * swallows SIGTERM (same root cause as TQ-M13): the parent and both
     * children stayed alive forever. Verified live pre-fix with a
     * manual probe (parent + 2 children alive 5s after SIGTERM).
     */
    public function testWorkAllWorkersTwoStopsOnSigterm(): void
    {
        $project = $this->makeQueueProject();
        $runner = $this->writeWorkAllRunner($project);
        $children = $this->startAssertRunningThenSigterm($project, [], 2, 5, $runner);
        $this->assertCount(2, $children, 'workAll(2) must fork exactly 2 children');
        foreach ($children as $child) {
            $this->assertPidDead($child);
        }
    }

    public function testApiTestWatchStopsOnSigterm(): void
    {
        $project = $this->makeApiProject();
        $this->startAssertRunningThenSigterm($project, ['api:test', 'GET', '/health/live', '--watch']);
    }

    public function testApiTestWebhookStopsOnSigterm(): void
    {
        $project = $this->makeApiProject();
        $this->startAssertRunningThenSigterm($project, ['api:test', 'GET', '/health/live', '--webhook', '--port=18133']);
    }
}
