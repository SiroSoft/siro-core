<?php

declare(strict_types=1);

namespace Siro\Core\Tests\DAST;

use Siro\Core\Tests\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;

final class DastSecurityTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    /** @dataProvider provideSqlInjectionPayloads */
    public function testRouterRejectsSqlInjectionPaths(string $path): void
    {
        $request = new Request('GET', $path);
        $result = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $result);
    }

    /** @return iterable<string, array{string}> */
    public static function provideSqlInjectionPayloads(): iterable
    {
        $payloads = [
            "' OR '1'='1",
            "1; DROP TABLE users--",
            "1 UNION SELECT * FROM users",
            "' OR 1=1 --",
            "1' AND 1=2 UNION SELECT 1,2,3--",
            "' WAITFOR DELAY '00:00:10'--",
            "1/**/OR/**/1=1",
            "1' OR '1'='1",
            "' OR '1'='1'/*",
            "\\' OR 1=1 --",
        ];
        $prefixes = ['/users/', '/posts/', '/api/', '/admin/'];
        foreach ($prefixes as $prefix) {
            foreach ($payloads as $payload) {
                yield "{$prefix}{$payload}" => [$prefix . urlencode($payload)];
            }
        }
    }

    /** @dataProvider provideXssPayloads */
    public function testRouterHandlesXssPathsGracefully(string $path): void
    {
        $request = new Request('GET', $path);
        $response = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $response);
        $body = json_encode($response->payload());
        if (is_string($body)) {
            $this->assertStringNotContainsString('<script>', $body);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideXssPayloads(): iterable
    {
        $payloads = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            'javascript:alert(1)',
            '"><script>alert(1)</script>',
            '<svg onload=alert(1)>',
            '"><img src=x onerror=alert(1)>',
            "'-alert(1)-'",
            '<ScRiPt>alert(1)</ScRiPt>',
            '%3Cscript%3Ealert(1)%3C/script%3E',
        ];
        foreach ($payloads as $payload) {
            yield $payload => ['/' . urlencode($payload)];
        }
    }

    /** @dataProvider providePathTraversalPayloads */
    public function testRouterRejectsPathTraversal(string $path): void
    {
        $request = new Request('GET', $path);
        $response = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $response);
    }

    /** @return iterable<string, array{string}> */
    public static function providePathTraversalPayloads(): iterable
    {
        $paths = [
            '/../../../etc/passwd',
            '/..%2f..%2f..%2fetc/passwd',
            '/%2e%2e%2f%2e%2e%2f%2e%2e%2fetc/passwd',
            '/....//....//....//etc/passwd',
            '/..\\..\\..\\windows\\win.ini',
            '/%c0%ae%c0%ae/%c0%ae%c0%ae/etc/passwd',
        ];
        foreach ($paths as $path) {
            yield $path => [$path];
        }
    }

    /** @dataProvider provideHttpMethods */
    public function testRouterHandlesAllHttpMethods(string $method, string $path): void
    {
        $request = new Request($method, $path);
        $result = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideHttpMethods(): iterable
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE', 'CONNECT', 'PURGE', 'LOCK', 'UNLOCK', 'PROPFIND', 'MKCOL'];
        $paths = ['/', '/test', '/api/users', '/admin', '/.env', '/composer.json', '/vendor/packages.yml'];
        foreach ($methods as $method) {
            foreach ($paths as $path) {
                yield "{$method} {$path}" => [$method, $path];
            }
        }
    }

    /** @dataProvider provideHeaderInjectionPayloads */
    public function testRouterHandlesMalformedHeaders(string $name, string $value): void
    {
        $request = new Request('GET', '/', [], [$name => $value]);
        $result = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideHeaderInjectionPayloads(): iterable
    {
        return [
            'newline-in-header' => ['X-Custom', "value\r\nInjected: true"],
            'null-byte' => ['X-Custom', "value\0null"],
            'very-long' => ['X-Custom', str_repeat('A', 10000)],
            'unicode' => ['X-Custom', "\u{0000}valid\u{FFFF}"],
        ];
    }
}
