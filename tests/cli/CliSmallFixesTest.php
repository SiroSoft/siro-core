<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\QueueWorkCommand;
use Siro\Core\Console;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Queue;

/**
 * Regression tests for the 6 small CLI eval items (CLI-EVAL-2026-11-27.md
 * §3 / Addendum 2) fixed 2026-11-28:
 *  (c) queue:work --tries works for the redis driver too (db path was
 *      fixed in the smoke round, redis was silently ignored),
 *  (d) --sleep default is 3s (Laravel parity),
 *  (f) log:export CSV no longer triggers the PHP 8.4 fputcsv() deprecation.
 *
 * (a) tinker exit 1 is covered by SmokeFixMetaTest,
 * (b) Queue::workAll(2) SIGTERM is covered by SmokeFixTestQueueTest,
 * (e) api:contract failing on unloadable route files is covered by
 * ApiContractCommandTest.
 */
final class CliSmallFixesTest extends TestCase
{
    private ?string $tempDir = null;
    private ?\Redis $redis = null;

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('QUEUE_DRIVER=db');
        $_ENV['QUEUE_DRIVER'] = 'db';
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        $this->createTables();
        Queue::reset();
    }

    protected function tearDown(): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->flushAll();
            } catch (\RedisException) {
                // server already gone — nothing to clean up
            }
            $this->redis = null;
        }
        Database::purgeAll();
        Queue::reset();
        putenv('QUEUE_DRIVER');
        unset($_ENV['QUEUE_DRIVER']);
        if ($this->tempDir !== null) {
            $this->rrmdir($this->tempDir);
            $this->tempDir = null;
        }
        parent::tearDown();
    }

    private function createTables(): void
    {
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

    private function drain(): void
    {
        $guard = 0;
        while (Queue::work() && $guard++ < 10) {
        }
    }

    private function jobsCount(): int
    {
        return (int) Database::first('SELECT COUNT(*) AS c FROM jobs')['c'];
    }

    private function failedCount(): int
    {
        return (int) Database::first('SELECT COUNT(*) AS c FROM failed_jobs')['c'];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $f
        ) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    // ==================== (c) --tries, db driver, work-time override ====================

    public function testTriesOverrideAppliesToJobsPushedAfterItIsSet(): void
    {
        // The old db fix rewrote pending rows (UPDATE jobs SET ...), so a
        // job pushed AFTER the worker started kept its own max_attempts.
        // The runtime override applies at work-time, so it covers those too.
        Queue::setMaxAttemptsOverride(5);
        Queue::push('App\\Jobs\\NoSuchJobXYZ', [], 0, 0, 1);
        $this->drain();
        $this->assertSame(0, $this->failedCount(), 'max_attempts=1 job must not fail under --tries=5');
        $this->assertSame(1, $this->jobsCount(), 'job must stay pending for retry');
    }

    public function testTriesOverrideAppliesToJobsPushedBeforeItIsSet(): void
    {
        Queue::push('App\\Jobs\\NoSuchJobXYZ', [], 0, 0, 1);
        Queue::setMaxAttemptsOverride(5);
        $this->drain();
        $this->assertSame(0, $this->failedCount());
        $this->assertSame(1, $this->jobsCount());
    }

    public function testNoOverrideUsesStoredMaxAttempts(): void
    {
        Queue::push('App\\Jobs\\NoSuchJobXYZ', [], 0, 0, 1);
        $this->drain();
        $this->assertSame(
            1,
            $this->failedCount(),
            'control: without --tries the job must fail after its own 1 attempt'
        );
        $this->assertSame(0, $this->jobsCount());
    }

    public function testOverrideIsClearedByReset(): void
    {
        Queue::setMaxAttemptsOverride(5);
        Queue::reset();
        Queue::push('App\\Jobs\\NoSuchJobXYZ', [], 0, 0, 1);
        $this->drain();
        $this->assertSame(1, $this->failedCount(), 'a cleared override must not leak into later work');
    }

    // ==================== (c) --tries, redis driver ====================

    /**
     * Skip when no usable Redis server is present (same convention as
     * RedisDriverMutationTest).
     */
    private function requireRedis(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not available');
        }
        $redis = new \Redis();
        $usable = false;
        try {
            if ($redis->connect('127.0.0.1', 6379, 1)) {
                $usable = $redis->ping() === true || $redis->ping() === '+PONG';
            }
        } catch (\RedisException) {
            $usable = false;
        }
        if (!$usable) {
            $this->markTestSkipped('No usable Redis server at 127.0.0.1:6379');
        }
        $redis->flushAll();
        $this->redis = $redis;
    }

    private function useRedisDriver(): void
    {
        putenv('QUEUE_DRIVER=redis');
        $_ENV['QUEUE_DRIVER'] = 'redis';
        Env::reset();
    }

    public function testTriesOverrideAppliesToRedisJobs(): void
    {
        $this->requireRedis();
        $this->useRedisDriver();
        Queue::setMaxAttemptsOverride(5);
        Queue::push('App\\Jobs\\NoSuchJobXYZ', [], 0, 0, 1);
        $this->drain();
        $this->assertSame(0, $this->failedCount(), 'redis job must not fail under --tries=5');
        $this->assertSame(
            1,
            (int) $this->redis->zCard('siro:queue:default:delayed'),
            'redis job must be released for retry, not lost'
        );
    }

    public function testRedisJobWithoutOverrideFailsAfterOwnMaxAttempts(): void
    {
        $this->requireRedis();
        $this->useRedisDriver();
        Queue::push('App\\Jobs\\NoSuchJobXYZ', [], 0, 0, 1);
        $this->drain();
        $this->assertSame(1, $this->failedCount(), 'control: redis job must fail after its own 1 attempt');
    }

    // ==================== (d) --sleep default ====================

    public function testQueueWorkDefaultSleepMatchesLaravel(): void
    {
        $this->assertSame(
            3,
            QueueWorkCommand::DEFAULT_SLEEP_SECONDS,
            'queue:work --sleep default must match Laravel (3s)'
        );
    }

    // ==================== (f) fputcsv() deprecation ====================

    public function testLogExportCsvTriggersNoDeprecation(): void
    {
        $dir = sys_get_temp_dir() . '/siro_cli_small_fixes_' . bin2hex(random_bytes(4));
        mkdir($dir . '/storage/logs/traces', 0777, true);
        $this->tempDir = $dir;
        file_put_contents($dir . '/.env', "APP_ENV=testing\nAPP_DEBUG=true\n");
        file_put_contents($dir . '/storage/logs/traces/fx_csv.json', json_encode([
            'trace_id' => 'fx_csv',
            'timestamp' => '2026-11-28 10:00:00',
            'method' => 'GET',
            'path' => '/api/products',
            'status' => 200,
            'time_ms' => 12,
            'ip' => '127.0.0.1',
            'host' => 'localhost:8080',
            'request_body' => '{}',
        ]));

        $deprecations = [];
        set_error_handler(static function (int $no, string $msg) use (&$deprecations): bool {
            if ($no === E_DEPRECATED) {
                $deprecations[] = $msg;
            }
            return true;
        });
        try {
            $console = new Console($dir);
            ob_start();
            $exit = $console->run(['siro', 'log:export', '--format=csv', 'fx_csv']);
            $output = (string) ob_get_clean();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('timestamp,method,path,status,time_ms,ip', $output);
        $this->assertSame([], $deprecations, 'log:export CSV must not trigger PHP deprecations');
    }
}
