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
 *   php siro queue:work              # Process all available and exit
 *   php siro queue:work --daemon     # Run continuously
 *   php siro queue:work --tries=5    # Override max attempts
 *   php siro queue:work --sleep=3    # Sleep seconds between polls (daemon)
 *
 * @package Siro\Core\Commands
 */
final class QueueWorkCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $daemon = false;
        $sleep = 1;
        $maxAttempts = null;

        foreach ($args as $arg) {
            if ($arg === '--daemon') {
                $daemon = true;
            } elseif (str_starts_with($arg, '--sleep=')) {
                $sleep = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--tries=')) {
                $maxAttempts = max(1, (int) substr($arg, 8));
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
            $this->write('Daemon mode: watching for new jobs...');
            $processed = 0;
            $errors = 0;

            while (true) {
                try {
                    $count = Queue::workAll(10);
                    if ($count > 0) {
                        $processed += $count;
                        $this->write("Processed: {$count} (total: {$processed})");
                    }
                    $errors = 0;
                } catch (\Throwable $e) {
                    $errors++;
                    Logger::error($e);
                    $this->write('Error: ' . $e->getMessage());

                    if ($errors > 5) {
                        $this->write('Too many consecutive errors. Stopping worker.');
                        return 1;
                    }
                }

                sleep($sleep);
            }
        } else {
            $count = Queue::workAll();
            $this->write("Processed {$count} job(s).");
        }

        return 0;
    }
}
