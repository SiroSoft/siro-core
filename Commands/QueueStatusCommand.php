<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Database;
use Siro\Core\Queue;

/**
 * Display the current queue status and detailed statistics.
 *
 * Shows pending/processing/failed counts, average attempts,
 * oldest pending job, and recent failures.
 *
 * Usage:
 *   php siro queue:status
 *   php siro queue:status --failed=10
 *
 * @package Siro\Core\Commands
 */
final class QueueStatusCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $failedLimit = 5;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--failed=')) {
                $failedLimit = max(1, (int) substr($arg, 9));
            }
        }

        $app = new App($this->basePath);
        $app->boot();

        $pending = Queue::pendingCount();
        $failed = Queue::failedCount();

        // Extended stats
        $processing = 0;
        $avgAttempts = 0;
        $oldestPending = 0;
        try {
            $now = time();
            $procRow = Database::first("SELECT COUNT(*) AS cnt FROM jobs WHERE locked_until > {$now}");
            $processing = is_numeric($procRow['cnt'] ?? null) ? (int) $procRow['cnt'] : 0;
            $avgRow = Database::first("SELECT COALESCE(AVG(attempts), 0) AS avg_attempts FROM jobs");
            $avgAttempts = is_numeric($avgRow['avg_attempts'] ?? null) ? (int) $avgRow['avg_attempts'] : 0;
            $oldestRow = Database::first("SELECT MIN(available_at) AS oldest FROM jobs WHERE available_at <= {$now}");
            $oldestPending = is_numeric($oldestRow['oldest'] ?? null) ? (int) $oldestRow['oldest'] : 0;
        } catch (\Throwable) {
        }

        $this->write('');
        $this->write('  Queue Status');
        $this->write('  ' . str_repeat('=', 40));
        $this->write("  Pending:      " . number_format($pending));
        $this->write("  Processing:   " . number_format($processing));
        $this->write("  Failed:       " . number_format($failed));
        $this->write("  Avg attempts: " . number_format($avgAttempts, 1));
        if ($oldestPending > 0) {
            $this->write("  Oldest:       " . date('Y-m-d H:i:s', $oldestPending));
        }
        $this->write('  ' . str_repeat('=', 40));

        if ($failed > 0) {
            $this->write('');
            $this->write("  Recent failures (last {$failedLimit}):");

            $failedJobs = Queue::getFailedJobs($failedLimit);
            $this->table(
                ['ID', 'Job', 'Error', 'Failed At'],
                array_map(function (array $job): array {
                    return [
                        $this->safeStr($job['id'] ?? ''),
                        $this->safeStr($job['job'] ?? ''),
                        mb_substr($this->safeStr($job['error'] ?? ''), 0, 60),
                        $this->safeStr($job['failed_at'] ?? ''),
                    ];
                }, $failedJobs)
            );

            $this->write('');
            $this->write('  Use "php siro queue:retry all" to retry all failed jobs.');
            $this->write('  Use "php siro queue:flush" to clear failed jobs.');
        }

        $this->write('');
        return 0;
    }
}
