<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;
use PHPUnit\Framework\Attributes\DataProvider;


use Siro\Core\Tests\TestCase;
use Siro\Core\Request;

final class FuzzRequestTest extends TestCase
{
    #[DataProvider('provideConstructorParams')]
    public function testConstructorNeverThrows(string $method, string $path, array $query, array $headers, array $body, string $ip): void
    {
        $request = new Request($method, $path, $query, $headers, $body, $ip);
        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame(strtoupper($method), $request->method());
    }

    /** @return iterable<int, array{string, string, array, array, array, string}> */
    public static function provideConstructorParams(): iterable
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', ''];
        $paths = [
            '/', '//', '/test', '/users/123', '/a/b/c', '',
            "\0", "/\n", '/../../../etc/passwd',
            '/<script>alert(1)</script>', '/path with spaces',
            str_repeat('x', 100),
        ];
        $querySets = [[], ['key' => 'value'], ['q' => '']];
        $headerSets = [[], ['Content-Type' => 'application/json']];
        $bodySets = [[], ['name' => 'test'], ['null_val' => null]];
        $ips = ['127.0.0.1', '0.0.0.0', ''];

        $idx = 0;
        foreach ($methods as $method) {
            foreach ($paths as $path) {
                foreach ($querySets as $query) {
                    foreach ($headerSets as $headers) {
                        foreach ($bodySets as $body) {
                            foreach ($ips as $ip) {
                                yield $idx++ => [$method, $path, $query, $headers, $body, $ip];
                            }
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('provideInputVariations')]
    public function testInputMethodsNeverThrow(Request $request): void
    {
        $this->assertIsString($request->method());
        $this->assertIsString($request->path());
        $this->assertIsArray($request->headers());
        $this->assertIsArray($request->body());
        $this->assertIsArray($request->query());
        $this->assertIsArray($request->queryAll());
        $this->assertIsString($request->ip());
        $this->assertIsArray($request->all());

        $request->input('nonexistent');
        $request->input('nonexistent', 'default');
        $request->query('nonexistent');
        $request->query('nonexistent', 42);
        $request->header('nonexistent');
        $request->header('nonexistent', 'default');
        $request->int('nonexistent');
        $request->string('nonexistent');
        $request->bool('nonexistent');
        $request->float('nonexistent');
        $request->array('nonexistent');
        $this->assertTrue(true);
    }

    /** @return iterable<int, array{Request}> */
    public static function provideInputVariations(): iterable
    {
        yield 0 => [new Request('GET', '/', ['p' => '1'], ['Accept' => 'text/html'], ['name' => 'test'])];
        yield 1 => [new Request('POST', '/submit', [], ['Content-Type' => 'application/json'], ['email' => 'test@test.com'])];
        yield 2 => [new Request()];
        yield 3 => [new Request('', '')];
    }

    #[DataProvider('provideCacheKeyInputs')]
    public function testCacheKeyNeverThrows(Request $request): void
    {
        $key = $request->cacheKey();
        $this->assertIsString($key);
    }

    /** @return iterable<int, array{Request}> */
    public static function provideCacheKeyInputs(): iterable
    {
        yield 0 => [new Request('GET', '/users', ['page' => '1'])];
        yield 1 => [new Request('GET', '/')];
    }
}
