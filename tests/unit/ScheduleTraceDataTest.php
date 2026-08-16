<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Schedule;
use Siro\Core\Debug\TraceData;

/**
 * Schedule + TraceData tests.
 */
final class ScheduleTraceDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TraceData::reset();
    }

    public function testTraceDataResetAndSet(): void
    {
        TraceData::setRequestBody('{"a":1}');
        TraceData::setResponseBody('{"ok":true}');
        TraceData::setRequestHeaders(['X-Test' => '1']);
        TraceData::setException('RuntimeException', 'boom');
        TraceData::addMiddleware('handler', true, 0.5);
        TraceData::addQuery('SELECT 1', 1.2, 1);

        $data = TraceData::getAll();
        $this->assertSame('{"a":1}', $data['request_body']);
        $this->assertSame('{"ok":true}', $data['response_body']);
        $this->assertArrayHasKey('X-Test', $data['request_headers']);
        $this->assertSame('RuntimeException', $data['exception']['class']);
        $this->assertSame('boom', $data['exception']['message']);
        $this->assertCount(1, $data['middleware']);
        $this->assertCount(1, $data['queries']);
    }

    public function testTraceDataResetClears(): void
    {
        TraceData::setRequestBody('x');
        TraceData::reset();
        $data = TraceData::getAll();
        $this->assertArrayNotHasKey('request_body', $data);
    }

    public function testScheduleCommandAddsTask(): void
    {
        $schedule = new Schedule();
        $task = $schedule->command('php -v');
        $this->assertInstanceOf(\Siro\Core\ScheduleTask::class, $task);
    }

    public function testScheduleCallAddsTask(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(fn () => null);
        $this->assertInstanceOf(\Siro\Core\ScheduleTask::class, $task);
    }
}
