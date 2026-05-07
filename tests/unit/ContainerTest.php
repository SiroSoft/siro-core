<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Container;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = new Container();
        Container::setInstance($this->container);
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance = Container::getInstance();
        $this->assertSame(Container::getInstance(), $instance);
    }

    public function testBindAndMake(): void
    {
        $this->container->bind('foo', fn () => new \stdClass());
        $instance = $this->container->make('foo');
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testBindWithClassName(): void
    {
        $this->container->bind(\stdClass::class);
        $instance = $this->container->make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testSingleton(): void
    {
        $this->container->singleton(\stdClass::class);
        $a = $this->container->make(\stdClass::class);
        $b = $this->container->make(\stdClass::class);
        $this->assertSame($a, $b);
    }

    public function testInstance(): void
    {
        $obj = new \stdClass();
        $this->container->instance('my.instance', $obj);
        $this->assertSame($obj, $this->container->make('my.instance'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->container->has('nothing'));
        $this->container->bind('something', fn () => new \stdClass());
        $this->assertTrue($this->container->has('something'));
    }

    public function testClear(): void
    {
        $this->container->bind('foo', fn () => new \stdClass());
        $this->container->make('foo');
        $this->container->clear();
        $this->assertFalse($this->container->has('foo'));
    }

    public function testBindFreshInstance(): void
    {
        $this->container->bind(\stdClass::class);
        $a = $this->container->make(\stdClass::class);
        $b = $this->container->make(\stdClass::class);
        $this->assertNotSame($a, $b);
    }

    public function testCallWithString(): void
    {
        $this->container->bind('callable.test', fn () => new class {
            public function run(int $x): int { return $x * 2; }
        });
        $result = $this->container->call('callable.test@run', [5]);
        $this->assertSame(10, $result);
    }

    public function testCallWithArray(): void
    {
        $obj = new class {
            public function greet(string $name): string { return "Hello $name"; }
        };
        $result = $this->container->call([$obj, 'greet'], ['World']);
        $this->assertSame('Hello World', $result);
    }
}
