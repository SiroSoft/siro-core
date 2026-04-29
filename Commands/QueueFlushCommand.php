<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Queue;

/**
 * Clear all failed jobs from the database.
 *
 * Usage:
 *   php siro queue:flush       # Clear all failed jobs
 *   php siro queue:flush --yes # Skip confirmation
 *
 * @package Siro\Core\Commands
 */
final class QueueFlushCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $force = false;
        foreach ($args as $arg) {
            if ($arg === '--yes' || $arg === '-y') {
                $force = true;
            }
        }

        $app = new App($this->basePath);
        $app->boot();

        $count = Queue::failedCount();

        if ($count === 0) {
            $this->write('No failed jobs to flush.');
            return 0;
        }

        if (!$force) {
            $answer = $this->ask("Delete {$count} failed job(s)? (y/N): ");
            if (!in_array(strtolower($answer), ['y', 'yes'], true)) {
                $this->write('Cancelled.');
                return 0;
            }
        }

        $deleted = Queue::flushFailed();
        $this->write("Deleted {$deleted} failed job(s).");
        return 0;
    }
}
