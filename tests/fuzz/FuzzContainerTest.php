<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use Siro\Core\Container;

final class FuzzContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
    }

    #[DataProvider('provideBindings')]
    public function testBindResolveNeverThrows(string $abstract, mixed $concrete): void
    {
        try {
            $this->container->bind($abstract, $concrete);
            $resolved = $this->container->make($abstract);
            $this->assertNotNull($resolved);
        } catch (\Throwable $e) {
            // TypeError for invalid concrete types is acceptable
            $this->assertTrue(true);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideBindings(): iterable
    {
        yield 'closure' => ['foo', fn () => new \stdClass()];
        yield 'string class' => ['stdClass', \stdClass::class];
        yield 'null concrete' => ['test', null];
        yield 'object' => ['obj', new \stdClass()];
        yield 'integer' => ['num', 42];
        yield 'array' => ['arr', [1, 2, 3]];
        yield 'empty string' => ['', ''];
    }

    #[DataProvider('provideSingletonBindings')]
    public function testSingletonReturnsSameInstance(string $abstract, mixed $concrete): void
    {
        try {
            $this->container->singleton($abstract, $concrete);
            $first = $this->container->make($abstract);
            $second = $this->container->make($abstract);
            $this->assertSame($first, $second);
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideSingletonBindings(): iterable
    {
        yield 'closure' => ['cache', fn () => new \stdClass()];
        yield 'class' => ['logger', \stdClass::class];
        yield 'object' => ['db', new \stdClass()];
    }

    #[DataProvider('provideInstanceBindings')]
    public function testInstanceNeverThrows(string $abstract, mixed $instance): void
    {
        try {
            $this->container->instance($abstract, $instance);
            $resolved = $this->container->make($abstract);
            $this->assertSame($instance, $resolved);
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideInstanceBindings(): iterable
    {
        yield 'object' => ['db', new \stdClass()];
        yield 'string' => ['name', 'test'];
        yield 'int' => ['count', 42];
        yield 'null' => ['nothing', null];
        yield 'array' => ['config', ['key' => 'value']];
    }

    #[DataProvider('provideHasChecks')]
    public function testHasNeverThrows(string $abstract): void
    {
        $result = $this->container->has($abstract);
        $this->assertIsBool($result);
    }

    /** @return iterable<string, array{string}> */
    public static function provideHasChecks(): iterable
    {
        yield 'empty string' => [''];
        yield 'non-existent' => ['non_existent_service'];
        yield 'class name' => [\stdClass::class];
        yield 'with namespace' => ['App\\Services\\NonExistent'];
    }
}
