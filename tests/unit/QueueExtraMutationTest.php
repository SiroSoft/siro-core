<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Queue;

/**
 * Extra Queue branches: driverName, workAll empty, retryFailed all, dashboard.
 */
final class QueueExtraMutationTest extends TestCase
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
        Database::execute('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL, data TEXT, attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3, priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60, available_at INTEGER NOT NULL,
            locked_until INTEGER, created_at TEXT
        )');
        Database::execute('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL, data TEXT, error TEXT, failed_at TEXT
        )');
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

    public function testDriverName(): void
    {
        $this->assertSame('db', Queue::driverName());
    }

    public function testWorkAllEmptyQueue(): void
    {
        $processed = Queue::workAll(2);
        $this->assertSame(0, $processed);
    }

    public function testWorkAllSingleWorker(): void
    {
        Queue::registerJob(QEJob::class);
        Queue::push(QEJob::class, ['x' => 1]);
        $processed = Queue::workAll(1);
        $this->assertSame(1, $processed);
    }

    public function testRetryFailedAll(): void
    {
        Database::execute('INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (?, ?, ?, ?)', ['QEJob', '{}', 'e1', date('c')]);
        Database::execute('INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (?, ?, ?, ?)', ['QEJob', '{"a":1}', 'e2', date('c')]);
        $this->assertTrue(Queue::retryFailed('all'));
        $this->assertSame(0, Queue::failedCount());
        $this->assertSame(2, Queue::pendingCount());
    }

    public function testRetryFailedAllEmpty(): void
    {
        $this->assertFalse(Queue::retryFailed('all'));
    }

    public function testDashboardHasJobsCount(): void
    {
        Queue::registerJob(QEJob::class);
        Queue::push(QEJob::class, ['a' => 1]);
        $html = Queue::dashboardHtml();
        $this->assertStringContainsString('table', $html);
        $this->assertStringContainsString('QEJob', $html);
    }

    public function testGetFailedJobsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Database::execute('INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (?, ?, ?, ?)', ['Q', '{}', 'e', date('c')]);
        }
        $jobs = Queue::getFailedJobs(2);
        $this->assertCount(2, $jobs);
    }

    public function testAssertNotPushedThrows(): void
    {
        Queue::fake();
        Queue::registerJob(QEJob::class);
        Queue::push(QEJob::class, ['a' => 1]);
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        Queue::assertNotPushed(QEJob::class);
    }
}

final class QEJob
{
    public function handle(): void
    {
    }
}
