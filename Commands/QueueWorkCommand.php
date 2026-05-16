<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Queue;
use Siro\Core\Logger;

/**
 * Process jobs from the queue.
 *
 * Runs indefinitely in daemon mode, processing jobs as they become available.
 * Recommended to run via supervisor or nohup in production.
 *
 * Usage:
 *   php siro queue:work                # Process all available and exit
 *   php siro queue:work --daemon       # Run continuously
 *   php siro queue:work --daemon --workers=4   # Fork 4 workers
 *   php siro queue:work --tries=5      # Override max attempts
 *
 * @package Siro\Core\Commands
 */
final class QueueWorkCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $daemon = false;
        $maxAttempts = null;
        $workers = 1;

        foreach ($args as $arg) {
            if ($arg === '--daemon') {
                $daemon = true;
            } elseif (str_starts_with($arg, '--sleep=')) {
                // Ignored — sleep handled internally by workAll
            } elseif (str_starts_with($arg, '--tries=')) {
                $maxAttempts = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--workers=')) {
                $workers = max(1, (int) substr($arg, 10));
            }
        }

        $app = new App($this->basePath);
        $app->boot();

        $this->write('Queue worker started.');
        $startTime = date('Y-m-d H:i:s');
        $this->write("Started at: {$startTime}");
        if ($maxAttempts !== null) {
            $this->write("Max attempts: {$maxAttempts}");
        }

        if ($daemon) {
            $this->write("Daemon mode: {$workers} worker(s) watching for new jobs...");
            Queue::workAll($workers);
        } else {
            $count = 0;
            while (Queue::work()) {
                $count++;
            }
            $this->write("Processed {$count} job(s).");
        }

        return 0;
    }
}
