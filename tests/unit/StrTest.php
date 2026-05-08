<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Str;

final class StrTest extends TestCase
{
    public function testSlug(): void
    {
        $this->assertSame('hello-world', Str::slug('Hello World'));
        $this->assertSame('hello-world', Str::slug('Hello   World'));
        $this->assertSame('hello-world', Str::slug('Hello-World'));
        $this->assertSame('ni-hao', Str::slug('Ni Hao'));
    }

    public function testLimit(): void
    {
        $this->assertSame('He...', Str::limit('Hello World', 5));
        $this->assertSame('Hello World', Str::limit('Hello World', 20));
        $this->assertSame('测...', Str::limit('测试中文长度限制', 5));
    }

    public function testWords(): void
    {
        $this->assertSame('Hello...', Str::words('Hello World Test', 1));
        $this->assertSame('Hello World', Str::words('Hello World', 10));
    }

    public function testCamel(): void
    {
        $this->assertSame('helloWorld', Str::camel('hello_world'));
        $this->assertSame('helloWorld', Str::camel('hello-world'));
        $this->assertSame('helloWorld', Str::camel('Hello World'));
    }

    public function testStudly(): void
    {
        $this->assertSame('HelloWorld', Str::studly('hello_world'));
        $this->assertSame('HelloWorld', Str::studly('hello-world'));
        $this->assertSame('HelloWorld', Str::studly('hello world'));
    }

    public function testSnake(): void
    {
        $this->assertSame('hello_world', Str::snake('helloWorld'));
        $this->assertSame('hello_world', Str::snake('hello-world'));
        $this->assertSame('hello_world', Str::snake('hello world'));
    }

    public function testKebab(): void
    {
        $this->assertSame('hello-world', Str::kebab('helloWorld'));
        $this->assertSame('hello_world', Str::kebab('hello_world'));
    }

    public function testContains(): void
    {
        $this->assertTrue(Str::contains('Hello World', 'World'));
        $this->assertFalse(Str::contains('Hello World', 'Foo'));
    }

    public function testStartsWith(): void
    {
        $this->assertTrue(Str::startsWith('hello_world', 'hello'));
        $this->assertFalse(Str::startsWith('hello_world', 'world'));
    }

    public function testEndsWith(): void
    {
        $this->assertTrue(Str::endsWith('hello_world', 'world'));
        $this->assertFalse(Str::endsWith('hello_world', 'hello'));
    }

    public function testUpper(): void
    {
        $this->assertSame('HELLO', Str::upper('hello'));
        $this->assertSame('HELLO WORLD', Str::upper('Hello World'));
    }

    public function testLower(): void
    {
        $this->assertSame('hello', Str::lower('HELLO'));
        $this->assertSame('hello world', Str::lower('Hello World'));
    }

    public function testTitle(): void
    {
        $this->assertSame('Hello World', Str::title('hello world'));
        $this->assertSame('Hello World', Str::title('HELLO WORLD'));
    }

    public function testRandom(): void
    {
        $r1 = Str::random(16);
        $r2 = Str::random(16);
        $this->assertSame(16, strlen($r1));
        $this->assertNotSame($r1, $r2);
    }

    public function testSubstr(): void
    {
        $this->assertSame('World', Str::substr('Hello World', 6));
        $this->assertSame('Hello', Str::substr('Hello World', 0, 5));
    }

    public function testLength(): void
    {
        $this->assertSame(11, Str::length('Hello World'));
        $this->assertSame(4, Str::length('测试长度'));
    }
}
