<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Schedule;
use Siro\Core\ScheduleTask;

final class ScheduleTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $schedule = new Schedule();
        $this->assertInstanceOf(Schedule::class, $schedule);
    }

    public function testCommandReturnsScheduleTask(): void
    {
        $schedule = new Schedule();
        $task = $schedule->command('test:command');
        $this->assertInstanceOf(ScheduleTask::class, $task);
    }

    public function testCallReturnsScheduleTask(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(function () {});
        $this->assertInstanceOf(ScheduleTask::class, $task);
    }

    public function testEveryMinute(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->everyMinute();
        $this->assertTrue($task->isDue(time()));
    }

    public function testHourly(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->hourly();
        $this->assertIsBool($task->isDue(time()));
    }

    public function testDaily(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->daily();
        $this->assertIsBool($task->isDue(time()));
    }

    public function testDailyAt(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->dailyAt('06:30');
        $this->assertIsBool($task->isDue(time()));
    }

    public function testWeekly(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->weekly();
        $this->assertIsBool($task->isDue(time()));
    }

    public function testMonthly(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->monthly();
        $this->assertIsBool($task->isDue(time()));
    }

    public function testCronExpression(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->cron('* * * * *');
        $this->assertTrue($task->isDue(time()));
    }

    public function testWithoutOverlapping(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->everyMinute()->withoutOverlapping();
        $this->assertIsBool($task->isLocked());
    }

    public function testMarkRun(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->everyMinute()->withoutOverlapping();
        $this->assertTrue($task->isLocked());
    }

    public function testUnlock(): void
    {
        $task = new ScheduleTask('command', 'test:cmd');
        $task->everyMinute()->withoutOverlapping();
        $task->unlock();
        $this->assertFalse($task->isLocked());
    }
}