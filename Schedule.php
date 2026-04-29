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

            $task->markRun($now);

            try {
                if ($task->type === 'command') {
                    $this->runCommand((string) $task->task, $basePath);
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
        $name = $parts[0] ?? '';
        $args = array_slice($parts, 1);

        $console = new Console($basePath);
        $console->run([$name, ...$args]);
    }
}

final class ScheduleTask
{
    public string $type;
    public mixed $task;
    private string $expression = '* * * * *';
    private int $lastRun = 0;

    public function __construct(string $type, mixed $task)
    {
        $this->type = $type;
        $this->task = $task;
    }

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self     { return $this->cron('* * * * *'); }
    public function hourly(): self           { return $this->cron('0 * * * *'); }
    public function daily(): self            { return $this->cron('0 0 * * *'); }
    public function weekly(): self           { return $this->cron('0 0 * * 0'); }
    public function monthly(): self          { return $this->cron('0 0 1 * *'); }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time) + [0, 0];
        return $this->cron(sprintf('%d %d * * *', (int) $minute, (int) $hour));
    }

    public function isDue(int $now): bool
    {
        return $this->matchesCron($now);
    }

    public function markRun(int $now): void
    {
        $this->lastRun = $now;
    }

    private function matchesCron(int $now): bool
    {
        $parts = explode(' ', $this->expression);
        if (count($parts) !== 5) return false;

        return $this->cronMatch((int) date('i', $now), $parts[0])
            && $this->cronMatch((int) date('G', $now), $parts[1])
            && $this->cronMatch((int) date('j', $now), $parts[2])
            && $this->cronMatch((int) date('n', $now), $parts[3])
            && $this->cronMatch((int) date('w', $now), $parts[4]);
    }

    private function cronMatch(int $value, string $pattern): bool
    {
        if ($pattern === '*') return true;
        return (int) $pattern === $value;
    }
}
