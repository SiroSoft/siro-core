<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Queue;

/**
 * Queue database-backed tests: pending/failed counts, retry, flush.
 * Uses SQLite in-memory with real jobs + failed_jobs tables.
 */
final class QueueDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Env::load(__DIR__ . '/../../.env.example');
        putenv('QUEUE_DRIVER=db');
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
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
        Queue::reset();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        Queue::reset();
        parent::tearDown();
    }

    public function testPendingCountOnEmptyQueue(): void
    {
        $this->assertSame(0, Queue::pendingCount());
    }

    public function testPendingCountAfterPush(): void
    {
        Queue::push('TestJob', ['x' => 1]);
        $this->assertSame(1, Queue::pendingCount());
    }

    public function testFailedCountOnEmpty(): void
    {
        $this->assertSame(0, Queue::failedCount());
    }

    public function testFailedCountAfterInsert(): void
    {
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'TestJob', 'data' => '{"x":1}', 'error' => 'boom', 'failed_at' => date('Y-m-d H:i:s')]
        );
        $this->assertSame(1, Queue::failedCount());
    }

    public function testGetFailedJobsReturnsRows(): void
    {
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'TestJob', 'data' => '{"x":1}', 'error' => 'boom', 'failed_at' => date('Y-m-d H:i:s')]
        );
        $jobs = Queue::getFailedJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('TestJob', $jobs[0]['job'] ?? null);
    }

    public function testRetryFailedByIdRequeuesAndDeletes(): void
    {
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'TestJob', 'data' => '{"x":1}', 'error' => 'boom', 'failed_at' => date('Y-m-d H:i:s')]
        );
        $id = (int) Database::first("SELECT id FROM failed_jobs LIMIT 1")['id'];

        $ok = Queue::retryFailed($id);
        $this->assertTrue($ok);
        $this->assertSame(0, Queue::failedCount(), 'failed_jobs should be empty after retry');
        $this->assertSame(1, Queue::pendingCount(), 'job should be requeued');
    }

    public function testRetryFailedAll(): void
    {
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'JobA', 'data' => '{}', 'error' => 'e1', 'failed_at' => date('Y-m-d H:i:s')]
        );
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'JobB', 'data' => '{}', 'error' => 'e2', 'failed_at' => date('Y-m-d H:i:s')]
        );
        $ok = Queue::retryFailed('all');
        $this->assertTrue($ok);
        $this->assertSame(0, Queue::failedCount());
        $this->assertSame(2, Queue::pendingCount());
    }

    public function testFlushFailedClearsAll(): void
    {
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'JobA', 'data' => '{}', 'error' => 'e1', 'failed_at' => date('Y-m-d H:i:s')]
        );
        Database::execute(
            "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
            ['job' => 'JobB', 'data' => '{}', 'error' => 'e2', 'failed_at' => date('Y-m-d H:i:s')]
        );
        $cleared = Queue::flushFailed();
        $this->assertSame(2, $cleared);
        $this->assertSame(0, Queue::failedCount());
    }

    public function testRegisterAndAllowedJob(): void
    {
        Queue::registerJob('App\\Jobs\\MyJob');
        $this->assertTrue(Queue::isJobAllowed('App\\Jobs\\MyJob'));
        $this->assertFalse(Queue::isJobAllowed('App\\Jobs\\Unknown'));
    }
}
