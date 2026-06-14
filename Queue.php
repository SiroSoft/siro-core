<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Queue\RedisQueueDriver;
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
    private static ?RedisQueueDriver $redisDriver = null;

    /** @var array<string, true> Whitelist of allowed job classes */
    private static array $allowedJobs = [];

    /** Register a job class as allowed for queue processing */
    public static function registerJob(string $jobClass): void
    {
        self::$allowedJobs[$jobClass] = true;
    }

    /** Check if a job class is registered/allowed */
    public static function isJobAllowed(string $jobClass): bool
    {
        return isset(self::$allowedJobs[$jobClass]);
    }

    public static function reset(): void
    {
        self::$faked = false;
        self::$fakeJobs = [];
    }

    /** Register built-in core job classes */
    private static function ensureBuiltinJobsRegistered(): void
    {
        if (!isset(self::$allowedJobs[SendMailJob::class])) {
            self::$allowedJobs[SendMailJob::class] = true;
        }
    }

    private static function getRedisDriver(): ?RedisQueueDriver
    {
        if (self::$redisDriver === null) {
            $driver = new RedisQueueDriver();
            if ($driver->isAvailable()) {
                self::$redisDriver = $driver;
            }
        }
        return self::$redisDriver;
    }

    public static function driverName(): string
    {
        if (Env::get('QUEUE_DRIVER', 'db') === 'redis' && self::getRedisDriver() !== null) {
            return 'redis';
        }
        return 'db';
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
        $processing = 0;
        $failed = 0;
        $latest = [];
        $avgAttempts = 0;
        $totalProcessed = 0;
        try {
            $now = time();
            $pending = Database::table('jobs')->where('locked_until', null)->count();
            $processing = Database::table('jobs')->where('locked_until', '>', $now)->count();
            $failed = Database::table('failed_jobs')->count();
            $totalRow = Database::first("SELECT COUNT(*) AS cnt FROM jobs");
            $totalProcessed = is_numeric($totalRow['cnt'] ?? null) ? (int) $totalRow['cnt'] : 0;
            $avgRow = Database::first("SELECT COALESCE(AVG(attempts), 0) AS avg FROM jobs");
            $avgAttempts = round(is_numeric($avgRow['avg'] ?? null) ? (float) $avgRow['avg'] : 0.0, 1);
            $latest = Database::table('jobs')->orderBy('id', 'desc')->limit(10)->get();
        } catch (\Throwable) {
        }
        $successRate = $totalProcessed > 0 ? round((($totalProcessed - $failed) / $totalProcessed) * 100, 1) : 100;

        $rows = '';
        foreach ($latest as $j) {
            $jId = $j['id'] ?? '';
            $jJob = $j['job'] ?? '';
            $jAttempts = $j['attempts'] ?? 0;
            $jMaxAttempts = $j['max_attempts'] ?? 3;
            $jPriority = $j['priority'] ?? 0;
            $jAvailable = $j['available_at'] ?? 0;
            $lockedUntil = $j['locked_until'] ?? null;
            $status = (is_numeric($lockedUntil) && (int) $lockedUntil > time()) ? 'processing' : 'pending';
            $badge = $status === 'processing' ? '<span class="badge badge-blue">PROCESSING</span>' : '<span class="badge badge-green">PENDING</span>';
            $rows .= '<tr><td>' . htmlspecialchars(is_scalar($jId) ? (string) $jId : '', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td><td>' . htmlspecialchars(is_scalar($jJob) ? (string) $jJob : '', ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' ' . $badge . '</td>'
                . '<td>' . htmlspecialchars(is_scalar($jAttempts) ? (string) $jAttempts : '0', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '/' . htmlspecialchars(is_scalar($jMaxAttempts) ? (string) $jMaxAttempts : '3', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars(is_scalar($jPriority) ? (string) $jPriority : '0', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars(date('Y-m-d H:i:s', is_numeric($jAvailable) ? (int) $jAvailable : 0), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td></tr>';
        }

        return '<!DOCTYPE html><html><head><title>Queue Dashboard - Siro</title>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="refresh" content="10">'
            . '<style>body{font-family:-apple-system,sans-serif;max-width:960px;margin:0 auto;padding:20px;background:#f5f5f5}'
            . '.stat{display:inline-block;background:#fff;border-radius:8px;padding:20px 30px;margin:10px;box-shadow:0 1px 3px rgba(0,0,0,.1);text-align:center;min-width:160px}'
            . '.stat h3{margin:0;font-size:14px;color:#666}'
            . '.stat .num{font-size:32px;font-weight:700;color:#333}'
            . 'table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}'
            . 'th,td{padding:12px 16px;text-align:left;border-bottom:1px solid #eee}'
            . 'th{background:#fafafa;font-weight:600;color:#666;font-size:13px}'
            . 'h1{color:#333}'
            . '.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600}'
            . '.badge-green{background:#e6ffe6;color:#0a0}'
            . '.badge-blue{background:#e6f0ff;color:#06c}'
            . '.badge-red{background:#ffe6e6;color:#c00}</style></head><body>'
            . '<h1>Queue Dashboard <span style="font-size:14px;color:#999">auto-refresh 10s</span></h1>'
            . '<div class="stat"><h3>Pending</h3><div class="num">' . htmlspecialchars((string) $pending, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div></div>'
            . '<div class="stat"><h3>Processing</h3><div class="num">' . htmlspecialchars((string) $processing, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div></div>'
            . '<div class="stat"><h3>Failed</h3><div class="num">' . htmlspecialchars((string) $failed, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div></div>'
            . '<div class="stat"><h3>Success Rate</h3><div class="num">' . htmlspecialchars((string) $successRate, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '%</div></div>'
            . '<div class="stat"><h3>Avg Attempts</h3><div class="num">' . htmlspecialchars((string) $avgAttempts, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div></div>'
            . '<h2>Recent Jobs</h2>'
            . '<table><thead><tr><th>ID</th><th>Job</th><th>Attempts</th><th>Priority</th><th>Available</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<p style="color:#999;font-size:12px;margin-top:20px">Siro Queue Dashboard · <a href="/metrics">Metrics</a></p></body></html>';
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
        self::ensureBuiltinJobsRegistered();

        if (self::$faked) {
            self::$fakeJobs[] = ['job' => $job, 'data' => $data];
            return;
        }

        if (Env::get('QUEUE_DRIVER', 'db') === 'redis') {
            $redis = self::getRedisDriver();
            if ($redis !== null) {
                $payload = json_encode([
                    'job' => $job,
                    'data' => $data,
                    'attempts' => 0,
                    'max_attempts' => max(1, $maxAttempts),
                    'timeout' => max(30, $timeout),
                    'created_at' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($payload)) { return; }

                if ($delay > 0) {
                    $redis->release('default', $payload, $delay);
                } else {
                    $redis->push('default', $payload);
                }
                return;
            }
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
        self::ensureBuiltinJobsRegistered();

        if (Env::get('QUEUE_DRIVER', 'db') === 'redis') {
            return self::workRedis();
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $now = time();
            $row = Database::first(
                "SELECT * FROM jobs WHERE available_at <= :now AND (locked_until IS NULL OR locked_until < :now2) ORDER BY priority DESC, id ASC LIMIT 1",
                ['now' => $now, 'now2' => $now]
            );

            if ($row === null) {
                $pdo->commit();
                return false;
            }

            $lockDuration = min(is_numeric($row['timeout'] ?? null) ? (int) $row['timeout'] : 60, 3600);
            $affected = Database::execute(
                "UPDATE jobs SET locked_until = :lock WHERE id = :id AND (locked_until IS NULL OR locked_until < :now3)",
                ['lock' => $now + $lockDuration, 'id' => $row['id'], 'now3' => $now]
            );

            if ($affected === 0) {
                $pdo->commit();
                return false;
            }

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
            $rowData = is_string($row['data'] ?? null) ? $row['data'] : '[]';
            $decodedData = json_decode($rowData, true);
            /** @var array<string, mixed> $jobData */
            $jobData = is_array($decodedData) ? $decodedData : [];
            $timeoutVal = isset($row['timeout']) && is_numeric($row['timeout']) ? (int) $row['timeout'] : self::DEFAULT_TIMEOUT;
            $timeout = $timeoutVal;
            $maxExecTime = time() + $timeout;

            $rowJob = $row['job'] ?? '';
            if (is_string($rowJob) && $rowJob !== '') {
                if (!self::isJobAllowed($rowJob)) {
                    throw new \RuntimeException("Job class '{$rowJob}' is not registered in the allowed jobs whitelist. Use Queue::registerJob() to allow it.");
                }
                if (!class_exists($rowJob)) {
                    throw new \RuntimeException("Job class '{$rowJob}' does not exist.");
                }

                if (!is_subclass_of($rowJob, QueueInterface::class)) {
                    throw new \RuntimeException("Job class '{$rowJob}' must implement QueueInterface.");
                }

                $instance = new $rowJob();

                self::executeWithTimeout(function () use ($instance, $jobData): void {
                    $instance->handle($jobData);
                }, $maxExecTime);
                $success = true;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Logger::error($e);
        }

        if ($success) {
            Database::execute("DELETE FROM jobs WHERE id = :id", ['id' => $row['id']]);
        } else {
            $attemptsVal = $row['attempts'] ?? 0;
            $attempts = (is_numeric($attemptsVal) ? (int) $attemptsVal : 0) + 1;
            $maxAttemptsVal = $row['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS;
            $maxAttempts = is_numeric($maxAttemptsVal) ? (int) $maxAttemptsVal : self::DEFAULT_MAX_ATTEMPTS;

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

    private static function workRedis(): bool
    {
        $redis = self::getRedisDriver();
        if ($redis === null) {
            return false;
        }

        $redis->migrateDelayed('default');
        $raw = $redis->pop('default');
        if ($raw === null) {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return false;
        }

        /** @var array<string, mixed> $decoded */
        $job = isset($decoded['job']) && is_string($decoded['job']) ? $decoded['job'] : '';
        $jobData = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
        $attempts = isset($decoded['attempts']) && is_numeric($decoded['attempts']) ? (int) $decoded['attempts'] : 0;
        $maxAttempts = isset($decoded['max_attempts']) && is_numeric($decoded['max_attempts']) ? (int) $decoded['max_attempts'] : self::DEFAULT_MAX_ATTEMPTS;
        $timeout = isset($decoded['timeout']) && is_numeric($decoded['timeout']) ? (int) $decoded['timeout'] : self::DEFAULT_TIMEOUT;
        $maxExecTime = time() + $timeout;

        $success = false;
        $error = null;

        try {
            if ($job !== '') {
                if (!self::isJobAllowed($job)) {
                    throw new \RuntimeException("Job class '{$job}' is not registered in the allowed jobs whitelist. Use Queue::registerJob() to allow it.");
                }
                if (!class_exists($job)) {
                    throw new \RuntimeException("Job class '{$job}' does not exist.");
                }

                if (!is_subclass_of($job, QueueInterface::class)) {
                    throw new \RuntimeException("Job class '{$job}' must implement QueueInterface.");
                }

                $instance = new $job();

                /** @var array<string, mixed> $jobData */
                self::executeWithTimeout(function () use ($instance, $jobData): void {
                    $instance->handle($jobData);
                }, $maxExecTime);
                $success = true;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Logger::error($e);
        }

        if ($success) {
            return true;
        }

        $attempts++;
        $decoded['attempts'] = $attempts;

        if ($attempts >= $maxAttempts) {
            $error = $error ?? 'Max attempts reached';
            Database::execute(
                "INSERT INTO failed_jobs (job, data, error, failed_at) VALUES (:job, :data, :error, :failed_at)",
                [
                    'job' => $job,
                    'data' => $raw,
                    'error' => $error,
                    'failed_at' => date('Y-m-d H:i:s'),
                ]
            );
        } else {
            $backoff = self::calculateBackoff($attempts);
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $redis->release('default', $encoded, $backoff);
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
     * Uses set_time_limit() and periodic time checks within the execution.
     * Throws RuntimeException if execution exceeds maxExecTime.
     */
    private static function executeWithTimeout(callable $fn, int $maxExecTime): void
    {
        if (time() >= $maxExecTime) {
            throw new \RuntimeException('Job timed out before execution started');
        }

        $remaining = $maxExecTime - time();
        if ($remaining > 0) {
            @set_time_limit($remaining);
        }

        $fn();
    }

    /**
     * Process queue jobs using multiple worker processes.
     * Forks N children using pcntl_fork(), each calling work() in a loop.
     * Falls back to single-process if pcntl is unavailable (Windows).
     */
    public static function workAll(int $workers = 4): int
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_fork') || $workers <= 1) {
            $processed = 0;
            $deadline = time() + 86400 * 365;
            while (time() < $deadline) {
                try {
                    self::work();
                    $processed++;
                } catch (\Throwable) {
                }
                usleep(100000);
            }
            return $processed;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        // Purge DB connection before forking — each child needs its own PDO
        \Siro\Core\Database::purgeAll();

        /** @var array<int, int> $children */
        $children = [];

        if (function_exists('pcntl_signal')) {
            $killChildren = function () use (&$children): void {
                foreach ($children as $pid) {
                    if (function_exists('posix_kill')) {
                        @posix_kill($pid, SIGTERM);
                    }
                }
                exit(0);
            };
            pcntl_signal(SIGTERM, $killChildren);
            pcntl_signal(SIGINT, $killChildren);
        }

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                continue;
            }
            if ($pid === 0) {
                if (function_exists('pcntl_async_signals')) {
                    pcntl_async_signals(true);
                }
                pcntl_signal(SIGTERM, function (): void { exit(0); });
                pcntl_signal(SIGINT, function (): void { exit(0); });
                // Reconnect DB — child has its own connection after fork
                try {
                    \Siro\Core\Database::connection()->query('SELECT 1');
                } catch (\Throwable) {
                }
                $deadline = time() + 86400 * 365;
                while (time() < $deadline) {
                    try {
                        self::work();
                    } catch (\Throwable) {
                    }
                    usleep(100000);
                }
                exit(0);
            }
            $children[] = $pid;
        }

        $status = -1;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
            pcntl_waitpid($pid, $status);
        }

        return 0;
    }

    /**
     * Get the count of pending jobs in the queue.
     */
    public static function pendingCount(): int
    {
        try {
            $row = Database::first("SELECT COUNT(*) AS count FROM jobs WHERE available_at <= :now", ['now' => time()]);
            $countVal = $row['count'] ?? 0;
            return is_numeric($countVal) ? (int) $countVal : 0;
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
            $countVal = $row['count'] ?? 0;
            return is_numeric($countVal) ? (int) $countVal : 0;
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

        $strData = is_string($data) ? $data : (is_scalar($data) ? (string) $data : '');
        $decoded = json_decode($strData, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function retryFailed(int|string $id): bool
    {
        try {
            if ($id === 'all') {
                $rows = Database::select("SELECT * FROM failed_jobs");
                $count = 0;
                foreach ($rows as $row) {
                    $jobName = is_string($row['job'] ?? null) ? $row['job'] : '';
                    self::push(
                        $jobName,
                        self::decodeJobData($row['data'] ?? null),
                        0,
                        self::DEFAULT_PRIORITY,
                        self::DEFAULT_MAX_ATTEMPTS,
                        isset($row['timeout']) && is_numeric($row['timeout']) ? (int) $row['timeout'] : self::DEFAULT_TIMEOUT
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

            $jobName = is_string($row['job'] ?? null) ? $row['job'] : '';
            self::push(
                $jobName,
                self::decodeJobData($row['data'] ?? null),
                0,
                self::DEFAULT_PRIORITY,
                self::DEFAULT_MAX_ATTEMPTS,
                isset($row['timeout']) && is_numeric($row['timeout']) ? (int) $row['timeout'] : self::DEFAULT_TIMEOUT
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
        return Database::select("SELECT * FROM failed_jobs ORDER BY id DESC LIMIT :lim", ['lim' => max(1, (int) $limit)]);
    }
}
