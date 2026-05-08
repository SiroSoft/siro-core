<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Config;

final class ConfigAdvancedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertNull(Config::get('nonexistent'));
        $this->assertSame('default', Config::get('nonexistent', 'default'));
    }

    public function testGetWithDotNotation(): void
    {
        Config::set('app.name', 'Siro');
        Config::set('app.version', '0.16.1');
        Config::set('database.host', 'localhost');

        $this->assertSame('Siro', Config::get('app.name'));
        $this->assertSame('0.16.1', Config::get('app.version'));
        $this->assertSame('localhost', Config::get('database.host'));
    }

    public function testGetNestedDotNotation(): void
    {
        Config::set('services.api.endpoints.users', '/api/users');
        Config::set('services.api.endpoints.posts', '/api/posts');

        $this->assertSame('/api/users', Config::get('services.api.endpoints.users'));
        $this->assertSame('/api/posts', Config::get('services.api.endpoints.posts'));
    }

    public function testSetOverwritesExisting(): void
    {
        Config::set('app.name', 'First');
        Config::set('app.name', 'Second');

        $this->assertSame('Second', Config::get('app.name'));
    }

    public function testHasReturnsTrue(): void
    {
        Config::set('app.name', 'Siro');
        $this->assertTrue(Config::has('app.name'));
    }

    public function testHasReturnsFalse(): void
    {
        $this->assertFalse(Config::has('nonexistent'));
    }

    public function testHasWithNestedDot(): void
    {
        Config::set('services.api.key', 'secret');
        $this->assertTrue(Config::has('services.api.key'));
        $this->assertFalse(Config::has('services.other.key'));
    }

    public function testAllReturnsAllConfig(): void
    {
        Config::set('app.name', 'Siro');
        Config::set('app.version', '0.16.1');

        $all = Config::all();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('app', $all);
    }

    public function testFlushClearsAll(): void
    {
        Config::set('app.name', 'Siro');
        Config::set('database.host', 'localhost');

        Config::reset();

        $this->assertFalse(Config::has('app.name'));
        $this->assertFalse(Config::has('database.host'));
    }

    public function testGetReturnsDefaultForPartialPath(): void
    {
        Config::set('app.name', 'Siro');
        Config::set('app.version', '0.16.1');

        $this->assertSame('default', Config::get('app.nonexistent', 'default'));
    }

    public function testEmptyKeyReturnsDefault(): void
    {
        $this->assertSame('default', Config::get('', 'default'));
    }

    public function testArrayValue(): void
    {
        Config::set('options', ['debug' => true, 'level' => 5]);
        $result = Config::get('options');

        $this->assertIsArray($result);
        $this->assertTrue($result['debug']);
        $this->assertSame(5, $result['level']);
    }

    public function testSetWithEmptyValue(): void
    {
        Config::set('empty_key', '');
        $this->assertSame('', Config::get('empty_key'));
        $this->assertTrue(Config::has('empty_key'));
    }

    public function testSetWithNullValue(): void
    {
        Config::set('null_key', null);
        $this->assertNull(Config::get('null_key'));
    }

    public function testMultipleFlushCalls(): void
    {
        Config::set('key1', 'value1');
        Config::reset();
        Config::set('key2', 'value2');
        Config::reset();

        $this->assertFalse(Config::has('key1'));
        $this->assertFalse(Config::has('key2'));
    }

    public function testDeepNestedSet(): void
    {
        Config::set('a.b.c.d', 'deep');
        $this->assertSame('deep', Config::get('a.b.c.d'));
    }

    public function testDeepNestedOverwrite(): void
    {
        Config::set('a.b.c', 'first');
        Config::set('a.b.c', 'second');
        $this->assertSame('second', Config::get('a.b.c'));
    }
}