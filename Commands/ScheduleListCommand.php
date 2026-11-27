<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * List scheduled tasks defined in routes/schedule.php.
 *
 * Shows each task's command/callback, cron expression, type,
 * whether it is due now, and its next run time.
 *
 * @package Siro\Core\Commands
 */
final class ScheduleListCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $schedule = new \Siro\Core\Schedule();

        $scheduleFile = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'schedule.php';
        if (!is_file($scheduleFile)) {
            $this->write('No schedule file found: routes/schedule.php');
            $this->write('Create it to define scheduled tasks.');
            return 0;
        }

        require $scheduleFile;
        $tasks = $schedule->tasks();

        if ($tasks === []) {
            $this->write('No scheduled tasks defined.');
            return 0;
        }

        $now = time();
        $rows = [];
        foreach ($tasks as $i => $task) {
            $next = $task->nextRun($now);
            $rows[] = [
                (string) ($i + 1),
                $task->describe(),
                $task->expression(),
                $task->type,
                $task->isDue($now) ? 'yes' : 'no',
                $next !== null ? date('Y-m-d H:i', $next) : '-',
            ];
        }

        $this->table(
            ['#', 'Task', 'Expression', 'Type', 'Due now', 'Next run'],
            $rows
        );

        return 0;
    }
}
