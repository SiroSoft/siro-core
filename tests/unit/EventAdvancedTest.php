<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Event;

final class EventAdvancedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::flush();
    }

    public function testOnAndEmit(): void
    {
        $called = false;
        Event::on('test.event', function () use (&$called) {
            $called = true;
        });

        Event::emit('test.event');
        $this->assertTrue($called);
    }

    public function testOnWithPayload(): void
    {
        $received = null;
        Event::on('test.payload', function ($data) use (&$received) {
            $received = $data;
        });

        Event::emit('test.payload', ['key' => 'value']);
        $this->assertSame(['key' => 'value'], $received);
    }

    public function testMultipleListeners(): void
    {
        $count = 0;
        Event::on('test.multi', function () use (&$count) { $count++; });
        Event::on('test.multi', function () use (&$count) { $count++; });

        Event::emit('test.multi');
        $this->assertSame(2, $count);
    }

    public function testOnceListenerOnlyFiresOnce(): void
    {
        $count = 0;
        Event::once('test.once', function () use (&$count) { $count++; });

        Event::emit('test.once');
        Event::emit('test.once');
        $this->assertSame(1, $count);
    }

    public function testOffRemovesListener(): void
    {
        $count = 0;
        Event::on('test.off', function () use (&$count) { $count++; });

        Event::off('test.off');
        Event::emit('test.off');
        $this->assertSame(0, $count);
    }

    public function testWildcardMatching(): void
    {
        $called = false;
        Event::on('user.*', function () use (&$called) {
            $called = true;
        });

        Event::emit('user.created');
        $this->assertTrue($called);
    }

    public function testWildcardMultiple(): void
    {
        $events = [];
        Event::on('test.*', function ($event) use (&$events) {
            $events[] = $event;
        });

        Event::emit('test.one');
        Event::emit('test.two');
        Event::emit('test.three');

        $this->assertCount(3, $events);
    }

    public function testOffWithWildcard(): void
    {
        $count = 0;
        Event::on('db.*', function () use (&$count) { $count++; });

        Event::off('db.*');
        Event::emit('db.query');
        $this->assertSame(0, $count);
    }

    public function testHasListenersReturnsTrue(): void
    {
        Event::on('test.has', function () {});
        $this->assertTrue(Event::hasListeners('test.has'));
    }

    public function testHasListenersReturnsFalse(): void
    {
        $this->assertFalse(Event::hasListeners('nonexistent'));
    }

    public function testHasListenersWithWildcard(): void
    {
        Event::on('cache.*', function () {});
        $this->assertTrue(Event::hasListeners('cache.*'));
    }

    public function testEmitReturnsTrue(): void
    {
        Event::on('test.true', function () {});
        $result = Event::emit('test.true');
        $this->assertTrue($result);
    }

    public function testEmitReturnsFalseWhenListenerReturnsFalse(): void
    {
        Event::on('test.false', function () {
            return false;
        });
        $result = Event::emit('test.false');
        $this->assertFalse($result);
    }

    public function testListenerReturningFalseHaltsChain(): void
    {
        $callOrder = [];
        Event::on('test.halt', function () use (&$callOrder) {
            $callOrder[] = 'first';
            return false;
        });
        Event::on('test.halt', function () use (&$callOrder) {
            $callOrder[] = 'second';
        });

        Event::emit('test.halt');
        $this->assertSame(['first'], $callOrder);
    }

    public function testFlushRemovesAllListeners(): void
    {
        Event::on('test.flush', function () {});
        Event::flush();
        $this->assertFalse(Event::hasListeners('test.flush'));
    }

    public function testComplexWildcard(): void
    {
        $count = 0;
        Event::on('a.b.*', function () use (&$count) { $count++; });

        Event::emit('a.b.c');
        Event::emit('a.b.d');
        $this->assertSame(2, $count);
    }

    public function testPayloadPassthrough(): void
    {
        $received = null;
        Event::on('test.data', function ($payload) use (&$received) {
            $received = $payload;
        });

        $data = ['nested' => ['key' => 'value']];
        Event::emit('test.data', $data);
        $this->assertSame($data, $received);
    }
}