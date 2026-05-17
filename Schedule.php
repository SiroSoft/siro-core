<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Minimal task scheduler.
 *
 * Define tasks in routes/schedule.php, run every minute via cron:
 *   * * * * * php /path/to/siro schedule:run
 *
 * @package Siro\Core
 */
final class Schedule
{
    /** @var ScheduleTask[] */
    private array $tasks = [];

    public function command(string $command): ScheduleTask
    {
        $task = new ScheduleTask('command', $command);
        $this->tasks[] = $task;
        return $task;
    }

    public function call(callable $callback): ScheduleTask
    {
        $task = new ScheduleTask('call', $callback);
        $this->tasks[] = $task;
        return $task;
    }

    public function run(string $basePath): void
    {
        $now = time();

        foreach ($this->tasks as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            if ($task->isLocked()) {
                continue;
            }

            $task->markRun($now);

            try {
                if ($task->type === 'command') {
                    $this->runCommand(is_scalar($task->task) ? (string) $task->task : '', $basePath);
                } else {
                    $cb = $task->task;
                    if (is_callable($cb)) {
                        $cb();
                    }
                }
            } catch (\Throwable $e) {
                Logger::error($e);
            }
        }
    }

    private function runCommand(string $command, string $basePath): void
    {
        $parts = explode(' ', $command);
        $name = $parts[0];
        $args = array_slice($parts, 1);

        $console = new Console($basePath);
        $console->run([$name, ...$args]);
    }
}


