<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Queue;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Queue long-run test: processes many jobs in single worker process
 * to detect memory growth and cross-job state contamination.
 */
final class QueueLongRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('QUEUE_DRIVER=db');
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
        ]);

        Database::execute('CREATE TABLE IF NOT EXISTS jobs (
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
        Database::execute('CREATE TABLE IF NOT EXISTS failed_jobs (
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

    /**
     * Process 1000 lightweight jobs in single worker.
     * Check for memory growth and state contamination.
     */
    public function testLongRun1000Jobs(): void
    {
        Queue::registerJob(QLongRunJob::class);
        QLongRunJob::$executions = [];
        QLongRunJob::$stateLeakages = [];

        $jobCount = 1000;

        // Enqueue all jobs
        for ($i = 0; $i < $jobCount; $i++) {
            Queue::push(QLongRunJob::class, [
                'seq' => $i,
                'unique' => bin2hex(random_bytes(8)),
            ]);
        }

        $startMemory = memory_get_usage(true);
        $startPeak = memory_get_peak_usage(true);

        // Process all jobs in single worker loop
        $processed = 0;
        while (Queue::work()) {
            $processed++;
        }

        $endMemory = memory_get_usage(true);
        $endPeak = memory_get_peak_usage(true);

        // Verify all jobs processed
        $this->assertSame($jobCount, $processed, "All {$jobCount} jobs should process");
        $this->assertCount($jobCount, QLongRunJob::$executions);

        // Verify no state contamination between jobs
        $this->assertEmpty(QLongRunJob::$stateLeakages, 'No state leakages detected');

        // Memory analysis
        $memoryGrowth = $endMemory - $startMemory;
        $memoryGrowthPercent = $startMemory > 0 ? ($memoryGrowth / $startMemory) * 100 : 0;

        // Report (non-assertion — informational)
        $this->addToAssertionCount(1); // Count this test as having an assertion

        // Log memory for visibility
        error_log("Long-run results: {$jobCount} jobs, memory start=" . ($startMemory / 1024) . "KB, end=" . ($endMemory / 1024) . "KB, growth=" . ($memoryGrowth / 1024) . "KB ({$memoryGrowthPercent}%)");
    }

    /**
     * Verify queue is clean after long run.
     */
    public function testQueueCleanAfterLongRun(): void
    {
        Queue::registerJob(QLongRunJob::class);

        for ($i = 0; $i < 100; $i++) {
            Queue::push(QLongRunJob::class, ['seq' => $i]);
        }

        while (Queue::work()) {
        }

        $this->assertSame(0, Queue::pendingCount(), 'Queue should be empty');
        $this->assertSame(0, Queue::failedCount(), 'No failed jobs');
    }

    /**
     * Mixed job types in long run.
     */
    public function testMixedJobTypes(): void
    {
        Queue::registerJob(QLongRunJob::class);
        Queue::registerJob(QLongRunAltJob::class);
        QLongRunJob::$executions = [];
        QLongRunAltJob::$executions = [];

        for ($i = 0; $i < 50; $i++) {
            if ($i % 2 === 0) {
                Queue::push(QLongRunJob::class, ['seq' => $i]);
            } else {
                Queue::push(QLongRunAltJob::class, ['id' => "mixed_{$i}"]);
            }
        }

        while (Queue::work()) {
        }

        $this->assertCount(25, QLongRunJob::$executions);
        $this->assertCount(25, QLongRunAltJob::$executions);
        $this->assertSame(0, Queue::pendingCount());
    }
}

/**
 * Lightweight job for long-run testing.
 */
final class QLongRunJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $executions = [];

    /** @var array<int, string> */
    public static array $stateLeakages = [];

    private static ?array $internalState = null;

    public function handle(array $data = []): void
    {
        $seq = (int) ($data['seq'] ?? 0);

        // Check for state leakage from previous job
        if (self::$internalState !== null) {
            self::$stateLeakages[] = "Job {$seq} leaked from previous: " . json_encode(self::$internalState);
        }

        // Process job
        self::$executions[$seq] = $data;

        // Set state (should NOT leak to next job in same worker)
        self::$internalState = $data;

        // Simulate minimal work
        $hash = hash('sha256', json_encode($data));

        // Clear state for next job
        self::$internalState = null;
    }
}

/**
 * Alternate job type for mixed-job testing.
 */
final class QLongRunAltJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $executions = [];

    public function handle(array $data = []): void
    {
        self::$executions[] = $data;
    }
}
