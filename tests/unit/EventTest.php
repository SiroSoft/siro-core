<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Event;

final class EventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Event::flush();
    }

    public function testOnAndEmit(): void
    {
        $result = null;
        Event::on('test.event', function ($payload) use (&$result) {
            $result = $payload;
        });
        Event::emit('test.event', 'hello');
        $this->assertSame('hello', $result);
    }

    public function testMultipleListeners(): void
    {
        $results = [];
        Event::on('multi.event', function ($p) use (&$results) { $results[] = 'a:' . $p; });
        Event::on('multi.event', function ($p) use (&$results) { $results[] = 'b:' . $p; });
        Event::emit('multi.event', 'test');
        $this->assertSame(['a:test', 'b:test'], $results);
    }

    public function testWildcardListener(): void
    {
        $events = [];
        Event::on('user.*', function ($payload, $event = null) use (&$events) {
            $events[] = $payload;
        });
        Event::emit('user.created', 'created');
        Event::emit('user.updated', 'updated');
        $this->assertSame(['created', 'updated'], $events);
    }

    public function testOnceListener(): void
    {
        $count = 0;
        Event::once('once.event', function () use (&$count) { $count++; });
        Event::emit('once.event');
        Event::emit('once.event');
        $this->assertSame(1, $count);
    }

    public function testOff(): void
    {
        $count = 0;
        Event::on('off.event', function () use (&$count) { $count++; });
        Event::emit('off.event');
        $this->assertSame(1, $count);
        Event::off('off.event');
        Event::emit('off.event');
        $this->assertSame(1, $count);
    }

    public function testHasListeners(): void
    {
        $this->assertFalse(Event::hasListeners('check.event'));
        Event::on('check.event', fn() => null);
        $this->assertTrue(Event::hasListeners('check.event'));
    }

    public function testEmitReturnsTrueWhenNoCancellation(): void
    {
        Event::on('ok.event', fn() => null);
        $result = Event::emit('ok.event');
        $this->assertTrue($result);
    }

    public function testEmitReturnsFalseOnCancellation(): void
    {
        Event::on('cancel.event', fn() => false);
        $result = Event::emit('cancel.event');
        $this->assertFalse($result);
    }

    public function testFlushRemovesAll(): void
    {
        Event::on('flush.event', fn() => null);
        $this->assertTrue(Event::hasListeners('flush.event'));
        Event::flush();
        $this->assertFalse(Event::hasListeners('flush.event'));
    }

    public function testEmitWithNoListeners(): void
    {
        $result = Event::emit('nonexistent.event');
        $this->assertTrue($result);
    }
}
