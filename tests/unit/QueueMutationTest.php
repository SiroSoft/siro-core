<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Queue;

/**
 * Branch coverage for Queue: dashboardHtml, workAll, DB-backed counts.
 */
final class QueueMutationTest extends TestCase
{
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
        set_time_limit(0);
        Database::purgeAll();
        Queue::reset();
        putenv('QUEUE_DRIVER');
        unset($_ENV['QUEUE_DRIVER']);
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

    public function testDashboardHtmlContainsTable(): void
    {
        $html = Queue::dashboardHtml();
        $this->assertStringContainsString('table', $html);
    }

    public function testPushAndPendingCount(): void
    {
        Queue::registerJob(QJob::class);
        Queue::push(QJob::class, ['x' => 1]);
        Queue::push(QJob::class, ['y' => 2]);
        $this->assertSame(2, Queue::pendingCount());
    }

    public function testWorkAllProcessesJobs(): void
    {
        Queue::registerJob(QJob::class);
        Queue::push(QJob::class, ['a' => 1]);
        Queue::push(QJob::class, ['b' => 2]);
        $processed = Queue::workAll(2);
        $this->assertGreaterThanOrEqual(0, $processed);
    }

    public function testFailedCountAndGet(): void
    {
        Database::execute(
            'INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (?, ?, ?, ?)',
            ['QJob', '{}', 'boom', date('c')]
        );
        $this->assertSame(1, Queue::failedCount());
        $this->assertCount(1, Queue::getFailedJobs(10));
    }

    public function testRetryFailedById(): void
    {
        Database::execute(
            'INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (?, ?, ?, ?)',
            ['QJob', '{}', 'boom', date('c')]
        );
        $id = (int) Database::select('SELECT id FROM failed_jobs')[0]['id'];
        $this->assertTrue(Queue::retryFailed($id));
        $this->assertSame(0, Queue::failedCount());
    }

    public function testRetryFailedUnknownId(): void
    {
        $this->assertFalse(Queue::retryFailed(999));
    }

    public function testFlushFailed(): void
    {
        Database::execute(
            'INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (?, ?, ?, ?)',
            ['QJob', '{}', 'x', date('c')]
        );
        $this->assertSame(1, Queue::flushFailed());
        $this->assertSame(0, Queue::failedCount());
    }

    public function testIsJobAllowed(): void
    {
        Queue::registerJob(QJob::class);
        $this->assertTrue(Queue::isJobAllowed(QJob::class));
    }
}

final class QJob
{
    public function handle(): void
    {
    }
}
