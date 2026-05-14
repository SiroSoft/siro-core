<?php

declare(strict_types=1);

namespace Siro\Core;

use Throwable;

/**
 * DB-based job queue.
 *
 * Push jobs to the database, then process them with queue:work.
 * Supports automatic retry with exponential backoff, priority,
 * job timeouts, and class-based jobs.
 *
 * Usage:
 *   Queue::push(SendEmail::class, ['to' => '...'])
 *
 * Run worker:
 *   php siro queue:work
 *   php siro queue:work --daemon
 *
 * @package Siro\Core
 */
final class Queue
{
    private static bool $faked = false;
    /** @var array<int, array{job: string, data: mixed}> */
    private static array $fakeJobs = [];

    public static function reset(): void
    {
        self::$faked = false;
        self::$fakeJobs = [];
    }

    public static function fake(): void
    {
        self::$faked = true;
        self::$fakeJobs = [];
    }

    /** @return array<int, array{job: string, data: mixed}> */
    public static function getFakedJobs(): array
    {
        return self::$fakeJobs;
    }

    public static function assertPushed(string $job, ?callable $callback = null): void
    {
        $matched = array_filter(self::$fakeJobs, fn($j) => $j['job'] === $job && ($callback === null || $callback($j['data'])));
        \PHPUnit\Framework\Assert::assertGreaterThan(0, count($matched), "Job {$job} was not pushed.");
    }

    public static function assertNotPushed(string $job): void
    {
        $matched = array_filter(self::$fakeJobs, fn($j) => $j['job'] === $job);
        \PHPUnit\Framework\Assert::assertCount(0, $matched, "Job {$job} was pushed unexpectedly.");
    }

    public static function dashboardHtml(): string
    {
        $pending = 0;
        $failed = 0;
        $latest = [];
        try {
            $pending = Database::table('jobs')->where('locked_until', null)->count();
            $failed = Database::table('failed_jobs')->count();
            $latest = Database::table('jobs')->orderBy('id', 'desc')->limit(10)->get();
        } catch (\Throwable) {
        }

        $rows = '';
        foreach ($latest as $j) {
            $rows .= '<tr><td>' . htmlspecialchars((string)($j['id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)($j['job'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string)($j['attempts'] ?? 0), ENT_QUOTES, 'UTF-8') . '/' . htmlspecialchars((string)($j['max_attempts'] ?? 3), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string)($j['priority'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars(date('Y-m-d H:i:s', $j['available_at'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        return '<!DOCTYPE html><html><head><title>Queue Dashboard - Siro</title>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{font-family:-apple-system,sans-serif;max-width:960px;margin:0 auto;padding:20px;background:#f5f5f5}'
            . '.stat{display:inline-block;background:#fff;border-radius:8px;padding:20px 30px;margin:10px;box-shadow:0 1px 3px rgba(0,0,0,.1);text-align:center}'
            . '.stat h3{margin:0;font-size:14px;color:#666}'
            . '.stat .num{font-size:32px;font-weight:700;color:#333}'
            . 'table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}'
            . 'th,td{padding:12px 16px;text-align:left;border-bottom:1px solid #eee}'
            . 'th{background:#fafafa;font-weight:600;color:#666;font-size:13px}'
            . 'h1{color:#333}'
            . '.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600}'
            . '.badge-green{background:#e6ffe6;color:#0a0}'
            . '.badge-red{background:#ffe6e6;color:#c00}</style></head><body>'
            . '<h1>Queue Dashboard</h1>'
            . '<div class="stat"><h3>Pending Jobs</h3><div class="num">' . $pending . '</div></div>'
            . '<div class="stat"><h3>Failed Jobs</h3><div class="num">' . $failed . '</div></div>'
            . '<div class="stat"><h3>Jobs / Table</h3><div class="num">jobs</div></div>'
            . '<h2>Recent Jobs</h2>'
            . '<table><thead><tr><th>ID</th><th>Job</th><th>Attempts</th><th>Priority</th><th>Available</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<p style="color:#999;font-size:12px;margin-top:20px">Siro Queue Dashboard</p></body></html>';
    }

    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_PRIORITY = 0;

    /**
     * Push a job onto the queue.
     *
     * @param string $job Fully-qualified class name with handle() method
     * @param mixed $data Data passed to the job's handle() method
     * @param int $delay Seconds to delay before making available
     * @param int $priority Higher priority jobs run first (default 0)
     * @param int $maxAttempts Maximum retry attempts (default 3)
     * @param int $timeout Max execution time in seconds (default 120)
     */
    public static function push(
        string $job,
        mixed $data = [],
        int $delay = 0,
        int $priority = self::DEFAULT_PRIORITY,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): void {
        if (self::$faked) {
            self::$fakeJobs[] = ['job' => $job, 'data' => $data];
            return;
        }

        $payload = [
            'job' => $job,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'attempts' => 0,
            'max_attempts' => max(1, $maxAttempts),
            'priority' => $priority,
            'timeout' => max(30, $timeout),
            'available_at' => time() + max(0, $delay),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        Database::table('jobs')->insert($payload);
    }

    /**
     * Process the next available job.
     * Returns true if a job was processed, false if queue is empty.
     */
    public static function work(): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $lockCheck = $driver === 'sqlite'
                ? "(locked_until IS NULL OR locked_until < :now)"
                : "(locked_until IS NULL OR locked_until < UNIX_TIMESTAMP())";

            $row = Database::first(
                "SELECT * FROM jobs WHERE available_at <= :now AND {$lockCheck} ORDER BY priority DESC, id ASC LIMIT 1",
                ['now' => time()]
            );

            if ($row === null) {
                $pdo->commit();
                return false;
            }

            Database::execute(
                "UPDATE jobs SET locked_until = :lock WHERE id = :id",
                ['lock' => time() + 60, 'id' => $row['id']]
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        $success = false;
        $error = null;

        try {
            $jobData = json_decode((string) $row['data'], true);
            if (!is_array($jobData)) {
                $jobData = [];
            }
            $timeout = (int) ($row['timeout'] ?? self::DEFAULT_TIMEOUT);
            $maxExecTime = time() + $timeout;

            if (class_exists($row['job'])) {
                $class = $row['job'];
                $instance = new $class();

                if (method_exists($instance, 'handle')) {
                    $handler = $instance->handle(...);
                    self::executeWithTimeout(function () use ($handler, $jobData): void {
                        $handler($jobData);
                    }, $maxExecTime);
                    $success = true;
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Logger::error($e);
        }

        if ($success) {
            Database::execute("DELETE FROM jobs WHERE id = :id", ['id' => $row['id']]);
        } else {
            $attempts = ((int) ($row['attempts'] ?? 0)) + 1;
            $maxAttempts = (int) ($row['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS);

            if ($attempts >= $maxAttempts) {
                Database::execute(
                    "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
                    [
                        'job' => $row['job'],
                        'data' => $row['data'],
                        'error' => $error ?? 'Unknown error',
                        'failed_at' => date('Y-m-d H:i:s'),
                    ]
                );
                Database::execute("DELETE FROM jobs WHERE id = :id", ['id' => $row['id']]);
            } else {
                $backoff = self::calculateBackoff($attempts);
                Database::execute(
                    "UPDATE jobs SET attempts = :attempts, locked_until = NULL, available_at = :available_at WHERE id = :id",
                    [
                        'attempts' => $attempts,
                        'available_at' => time() + $backoff,
                        'id' => $row['id'],
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Calculate exponential backoff seconds based on attempt number.
     * Attempt 1: 5s, 2: 10s, 3: 20s, etc. Max 300s (5 min).
     */
    private static function calculateBackoff(int $attempt): int
    {
        return (int) min(5 * (2 ** ($attempt - 1)), 300);
    }

    /**
     * Execute a callable with a timeout check.
     * Throws RuntimeException if execution exceeds maxExecTime.
     */
    private static function executeWithTimeout(callable $fn, int $maxExecTime): void
    {
        if (time() >= $maxExecTime) {
            throw new \RuntimeException('Job timed out before execution started');
        }

        declare(ticks=1);
        $check = function () use ($maxExecTime): void {
            if (time() > $maxExecTime) {
                throw new \RuntimeException('Job timed out during execution');
            }
        };

        $registered = register_tick_function($check);
        if ($registered === false) {
            $fn();
            return;
        }

        try {
            $fn();
        } finally {
            unregister_tick_function($check);
        }
    }

    /**
     * Process all available jobs. Returns count of processed jobs.
     */
    public static function workAll(int $max = 100): int
    {
        $count = 0;
        while ($count < $max && self::work()) {
            $count++;
        }
        return $count;
    }

    /**
     * Get the count of pending jobs in the queue.
     */
    public static function pendingCount(): int
    {
        try {
            $row = Database::first("SELECT COUNT(*) AS count FROM jobs WHERE available_at <= :now", ['now' => time()]);
            return (int) ($row['count'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get the count of failed jobs.
     */
    public static function failedCount(): int
    {
        try {
            $row = Database::first("SELECT COUNT(*) AS count FROM failed_jobs");
            return (int) ($row['count'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Retry a specific failed job by re-pushing it to the queue.
     */
    private static function decodeJobData(mixed $data): mixed
    {
        if ($data === null || $data === '') {
            return [];
        }

        $decoded = json_decode((string) $data, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function retryFailed(int|string $id): bool
    {
        try {
            if ($id === 'all') {
                $rows = Database::select("SELECT * FROM failed_jobs");
                $count = 0;
                foreach ($rows as $row) {
                    self::push(
                        $row['job'],
                        self::decodeJobData($row['data']),
                        0,
                        self::DEFAULT_PRIORITY,
                        self::DEFAULT_MAX_ATTEMPTS
                    );
                    Database::execute("DELETE FROM failed_jobs WHERE id = :id", ['id' => $row['id']]);
                    $count++;
                }
                return $count > 0;
            }

            $row = Database::first("SELECT * FROM failed_jobs WHERE id = :id", ['id' => $id]);
            if ($row === null) {
                return false;
            }

            self::push(
                $row['job'],
                self::decodeJobData($row['data']),
                0,
                self::DEFAULT_PRIORITY,
                self::DEFAULT_MAX_ATTEMPTS
            );
            Database::execute("DELETE FROM failed_jobs WHERE id = :id", ['id' => $row['id']]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Flush all failed jobs.
     */
    public static function flushFailed(): int
    {
        $count = Database::execute("DELETE FROM failed_jobs");
        return $count;
    }

    /**
     * Get failed jobs list.
     * @return array<int, array<string, mixed>>
     */
    public static function getFailedJobs(int $limit = 50): array
    {
        return Database::select("SELECT * FROM failed_jobs ORDER BY id DESC LIMIT " . max(1, (int) $limit));
    }
}
