<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Database;
use Siro\Core\Queue;

/**
 * Display the current queue status.
 *
 * Shows pending job count, failed job count, and recent failures.
 *
 * Usage:
 *   php siro queue:status
 *   php siro queue:status --failed=10
 *
 * @package Siro\Core\Commands
 */
final class QueueStatusCommand
{
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

        $this->write('Queue Status');
        $this->write('────────────');
        $this->write("Pending jobs: {$pending}");
        $this->write("Failed jobs:  {$failed}");

        if ($failed > 0) {
            $this->write('');
            $this->write("Recent failures (last {$failedLimit}):");
            $this->write('');

            $failedJobs = Queue::getFailedJobs($failedLimit);
            $this->table(
                ['ID', 'Job', 'Error', 'Failed At'],
                array_map(fn (array $job): array => [
                    (string) ($job['id'] ?? ''),
                    $job['job'] ?? '',
                    mb_substr((string) ($job['error'] ?? ''), 0, 60),
                    $job['failed_at'] ?? '',
                ], $failedJobs)
            );

            $this->write('');
            $this->write('Use "php siro queue:retry all" to retry all failed jobs.');
            $this->write('Use "php siro queue:flush" to clear failed jobs.');
        }

        return 0;
    }
}
