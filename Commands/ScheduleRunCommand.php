<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Run scheduled tasks that are due.
 *
 * Add to crontab: * * * * * php /path/to/siro schedule:run
 *
 * @package Siro\Core\Commands
 */
final class ScheduleRunCommand implements \Siro\Core\Commands\CommandInterface {
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
        $schedule->run($this->basePath);

        return 0;
    }
}
