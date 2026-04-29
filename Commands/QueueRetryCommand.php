<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Queue;

/**
 * Retry failed jobs by re-pushing them to the queue.
 *
 * Usage:
 *   php siro queue:retry all        # Retry all failed jobs
 *   php siro queue:retry 5          # Retry failed job with ID 5
 *   php siro queue:retry 3 7 9     # Retry multiple failed jobs
 *
 * @package Siro\Core\Commands
 */
final class QueueRetryCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        if ($args === []) {
            $this->write('Usage: php siro queue:retry <id|all>');
            $this->write('  php siro queue:retry all    - Retry ALL failed jobs');
            $this->write('  php siro queue:retry 5      - Retry failed job ID 5');
            $this->write('  php siro queue:retry 3 7 9  - Retry multiple');
            return 1;
        }

        $app = new App($this->basePath);
        $app->boot();

        $count = 0;

        foreach ($args as $arg) {
            if ($arg === 'all') {
                if (Queue::retryFailed('all')) {
                    $this->write('All failed jobs requeued successfully.');
                } else {
                    $this->write('No failed jobs to retry.');
                }
                return 0;
            }

            $id = (int) $arg;
            if (Queue::retryFailed($id)) {
                $count++;
            }
        }

        $this->write("Requeued {$count} job(s).");
        return 0;
    }
}
