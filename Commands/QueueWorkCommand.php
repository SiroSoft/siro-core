<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Database;
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
 *   php siro queue:work --daemon --sleep=5     # Poll every 5s when idle
 *
 * @package Siro\Core\Commands
 */
final class QueueWorkCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $daemon = false;
        $maxAttempts = null;
        $sleep = 1;
        $workers = 1;

        foreach ($args as $arg) {
            if ($arg === '--daemon') {
                $daemon = true;
            } elseif (str_starts_with($arg, '--sleep=')) {
                $sleep = max(0, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--tries=')) {
                $maxAttempts = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--workers=')) {
                $workers = max(1, (int) substr($arg, 10));
            }
        }

        $app = new App($this->basePath);
        $app->boot();

        $driver = \Siro\Core\Queue::driverName();
        $this->write("Queue worker started. Driver: {$driver}");
        $startTime = date('Y-m-d H:i:s');
        $this->write("Started at: {$startTime}");
        if ($maxAttempts !== null) {
            $this->write("Max attempts: {$maxAttempts}");
            $this->applyMaxAttemptsOverride($maxAttempts);
        }

        if ($daemon) {
            $this->write("Daemon mode: {$workers} worker(s) watching for new jobs...");
            return $this->runDaemon($workers, $sleep, $maxAttempts);
        }

        $count = 0;
        while (Queue::work()) {
            $count++;
        }
        $this->write("Processed {$count} job(s).");

        return 0;
    }

    /**
     * --tries=N overrides the per-job max_attempts (TQ-M11).
     *
     * Queue::work() reads max_attempts from the jobs row, and the flag
     * previously only printed "Max attempts: N" without changing
     * anything — a job with max_attempts=1 still failed after 1 attempt.
     * Queue::work() takes no override parameter, so the override is
     * applied to the pending rows themselves (db driver only; the redis
     * driver keeps max_attempts inside the payload, which has no
     * equivalent update path here).
     */
    private function applyMaxAttemptsOverride(int $maxAttempts): void
    {
        if (Queue::driverName() !== 'db') {
            return;
        }
        try {
            Database::execute(
                'UPDATE jobs SET max_attempts = :max WHERE max_attempts != :max2',
                ['max' => $maxAttempts, 'max2' => $maxAttempts]
            );
        } catch (\Throwable) {
            // A failed override must not stop the worker; the job rows
            // simply keep their own max_attempts.
        }
    }

    /**
     * Daemon loop with real signal handling (TQ-M12 + TQ-M13).
     *
     * - workers=1 used to drain once and exit (Queue::workAll() only
     *   loops forever for workers>=2); here every worker count loops.
     * - The parent no longer blocks in pcntl_waitpid(): it polls with
     *   WNOHANG + pcntl_signal_dispatch(), so SIGTERM/SIGINT stops it,
     *   SIGTERMs the children and reaps them instead of orphaning them.
     */
    private function runDaemon(int $workers, int $sleep, ?int $maxAttempts): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $stopping = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$stopping): void {
                $stopping = true;
            });
            pcntl_signal(SIGINT, function () use (&$stopping): void {
                $stopping = true;
            });
        }

        $canFork = $workers >= 2
            && extension_loaded('pcntl')
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid');

        if (!$canFork) {
            $this->runSingleDaemonLoop($sleep, $maxAttempts, $stopping);
            $this->write('Daemon stopped.');
            App::shutdown();
            return 0;
        }

        // Purge DB connection before forking — each child needs its own PDO.
        Database::purgeAll();

        /** @var array<int, int> $children */
        $children = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                continue;
            }
            if ($pid === 0) {
                // ─── Child: work loop, exit(0) on SIGTERM/SIGINT. ───
                if (function_exists('pcntl_async_signals')) {
                    pcntl_async_signals(true);
                }
                if (function_exists('pcntl_signal')) {
                    pcntl_signal(SIGTERM, function (): void {
                        exit(0);
                    });
                    pcntl_signal(SIGINT, function (): void {
                        exit(0);
                    });
                }
                try {
                    Database::connection()->query('SELECT 1');
                } catch (\Throwable) {
                }
                $childStopping = false;
                $this->runSingleDaemonLoop($sleep, $maxAttempts, $childStopping);
                exit(0);
            }
            $children[] = $pid;
        }

        if ($children === []) {
            // Every fork failed — fall back to the single-process loop.
            $this->runSingleDaemonLoop($sleep, $maxAttempts, $stopping);
            $this->write('Daemon stopped.');
            App::shutdown();
            return 0;
        }

        // ─── Parent: supervise with non-blocking waits so signals are
        // dispatched (blocking pcntl_waitpid() swallowed SIGTERM). ───
        while (!$stopping && $children !== []) {
            $exited = pcntl_waitpid(-1, $status, WNOHANG);
            if ($exited > 0) {
                $children = array_values(array_filter($children, fn (int $p): bool => $p !== $exited));
                continue;
            }
            usleep(100000);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        // Stop: SIGTERM every remaining child, then reap them all so no
        // orphan worker keeps eating jobs after the parent is gone.
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->write('Daemon stopped.');
        App::shutdown();
        return 0;
    }

    /**
     * Single-process daemon loop: work until $stopping is set (SIGTERM/
     * SIGINT handler flips it). Sleeps $sleep seconds between polls
     * when the queue is empty (--sleep is honoured here; the old code
     * parsed it and then ignored it).
     */
    private function runSingleDaemonLoop(int $sleep, ?int $maxAttempts, bool &$stopping): void
    {
        while (!$stopping) {
            try {
                if ($maxAttempts !== null) {
                    $this->applyMaxAttemptsOverride($maxAttempts);
                }
                $worked = Queue::work();
            } catch (\Throwable) {
                $worked = false;
            }
            if (!$worked) {
                $this->sleepInterruptibly($sleep, $stopping);
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Sleep $seconds in 100ms chunks, returning early when $stopping is
     * set (SIGTERM/SIGINT). A single sleep($seconds) would delay
     * shutdown by up to $seconds after the signal arrived.
     */
    private function sleepInterruptibly(int $seconds, bool &$stopping): void
    {
        if ($seconds <= 0) {
            usleep(100000);
            return;
        }
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($stopping) {
                return;
            }
            usleep(100000);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
}
