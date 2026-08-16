<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Queue;
use Siro\Core\QueueInterface;

/**
 * Queue worker coverage: work() success/failure paths, retry, flush,
 * dashboard, and job registration edge cases.
 */
final class QueueWorkTest extends TestCase
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
        set_time_limit(0);
        Database::purgeAll();
        Queue::reset();
        putenv('QUEUE_DRIVER');
        unset($_ENV['QUEUE_DRIVER']);
        parent::tearDown();
    }

    private function insertJob(string $job, string $data = '{}', int $attempts = 0, int $maxAttempts = 3): int
    {
        Database::table('jobs')->insert([
            'job' => $job,
            'data' => $data,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'priority' => 0,
            'timeout' => 60,
            'available_at' => time() - 10,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public function testWorkEmptyQueueReturnsFalse(): void
    {
        $this->assertFalse(Queue::work());
    }

    public function testWorkSuccessDeletesJob(): void
    {
        Queue::registerJob(QWTestJob::class);
        $this->insertJob(QWTestJob::class, json_encode(['value' => 5]));
        $this->assertTrue(Queue::work());
        $this->assertSame(0, Database::table('jobs')->count());
    }

    public function testWorkJobNotAllowed(): void
    {
        // Use a class that is never registered (testWorkSuccessDeletesJob registers QWTestJob)
        $this->insertJob('Siro\\Core\\Tests\\Unit\\QWNeverRegisteredJob', '{}', 0, 1);
        $this->assertTrue(Queue::work());
        $row = Database::table('failed_jobs')->first();
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('not registered', (string) $row['error']);
    }

    public function testWorkJobClassDoesNotExist(): void
    {
        Queue::registerJob('Siro\\Core\\Tests\\Unit\\DoesNotExistJob');
        $this->insertJob('Siro\\Core\\Tests\\Unit\\DoesNotExistJob', '{}', 0, 1);
        $this->assertTrue(Queue::work());
        $row = Database::table('failed_jobs')->first();
        $this->assertStringContainsString('does not exist', (string) $row['error']);
    }

    public function testWorkJobNotQueueInterface(): void
    {
        Queue::registerJob(QWNotQueueInterface::class);
        $this->insertJob(QWNotQueueInterface::class, '{}', 0, 1);
        $this->assertTrue(Queue::work());
        $row = Database::table('failed_jobs')->first();
        $this->assertStringContainsString('must implement QueueInterface', (string) $row['error']);
    }

    public function testWorkJobFailsAndRetries(): void
    {
        Queue::registerJob(QWThrowingJob::class);
        $this->insertJob(QWThrowingJob::class, json_encode(['fail' => true]), 0, 3);
        $this->assertTrue(Queue::work());
        $remaining = Database::table('jobs')->first();
        $this->assertSame(1, (int) $remaining['attempts']);
        $this->assertNull($remaining['locked_until']);
    }

    public function testWorkJobExhaustsAttemptsMovesToFailed(): void
    {
        Queue::registerJob(QWThrowingJob::class);
        $this->insertJob(QWThrowingJob::class, json_encode(['fail' => true]), 2, 3);
        $this->assertTrue(Queue::work());
        $this->assertSame(0, Database::table('jobs')->count());
        $failed = Database::table('failed_jobs')->first();
        $this->assertNotEmpty($failed);
    }

    public function testWorkLockedJobSkipped(): void
    {
        Queue::registerJob(QWTestJob::class);
        $id = $this->insertJob(QWTestJob::class);
        Database::execute('UPDATE jobs SET locked_until = ? WHERE id = ?', [time() + 5000, $id]);
        $this->assertFalse(Queue::work());
        $this->assertSame(1, Database::table('jobs')->count());
    }

    public function testDashboardHtmlContainsTable(): void
    {
        $html = Queue::dashboardHtml();
        $this->assertIsString($html);
    }

    public function testFlushFailedClearsAll(): void
    {
        Database::table('failed_jobs')->insert(['job' => 'J', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(1, Queue::failedCount());
        Queue::flushFailed();
        $this->assertSame(0, Queue::failedCount());
    }

    public function testGetFailedJobsRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Database::table('failed_jobs')->insert(['job' => 'J', 'data' => '{}', 'error' => "e$i", 'failed_at' => date('Y-m-d H:i:s')]);
        }
        $this->assertCount(5, Queue::getFailedJobs(10));
        $this->assertCount(2, Queue::getFailedJobs(2));
    }

    public function testRetryFailedById(): void
    {
        Database::table('failed_jobs')->insert(['job' => 'J', 'data' => '{}', 'error' => 'e', 'failed_at' => date('Y-m-d H:i:s')]);
        $id = (int) Database::connection()->lastInsertId();
        $this->assertTrue(Queue::retryFailed($id));
        $this->assertSame(0, Database::table('failed_jobs')->count());
        $this->assertSame(1, Database::table('jobs')->count());
    }

    public function testRetryFailedUnknownId(): void
    {
        $this->assertFalse(Queue::retryFailed(99999));
    }
}

final class QWTestJob implements QueueInterface
{
    public function handle(array $data = []): void
    {
        // no-op success
    }
}

final class QWThrowingJob implements QueueInterface
{
    public function handle(array $data = []): void
    {
        throw new \RuntimeException('boom');
    }
}

final class QWNotQueueInterface
{
    public function handle(array $data = []): void
    {
    }
}
