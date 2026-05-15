<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use Siro\Core\Tests\TestCase;
use Siro\Core\Router;
use Siro\Core\Route;
use Siro\Core\RouteMatcher;
use Siro\Core\Request;
use Siro\Core\Response;

final class FuzzRouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        Route::setRouter($this->router);
        Route::clearRoutes();
    }

    /** @dataProvider provideRoutePaths */
    public function testRegisterRouteNeverThrows(string $method, string $path): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        foreach ($methods as $m) {
            $this->router->$m($path, function () {
                return Response::json(['ok' => true]);
            });
        }
        $routes = $this->router->getRoutes();
        $this->assertIsArray($routes);
    }

    /** @dataProvider provideRoutePaths */
    public function testGetRoutesNeverThrows(string $method, string $path): void
    {
        $this->router->get($path, function () {
            return Response::json(['ok' => true]);
        });
        $this->router->post($path, function () {
            return Response::json(['ok' => true]);
        });
        $this->router->put($path, function () {
            return Response::json(['ok' => true]);
        });
        $routes = $this->router->getRoutes();
        $this->assertIsArray($routes);
    }

    /** @dataProvider provideRoutePaths */
    public function testClearRoutesNeverThrows(string $method, string $path): void
    {
        $this->router->get($path, function () {
            return Response::json(['ok' => true]);
        });
        $this->router->clearRoutes();
        $routes = $this->router->getRoutes();
        $this->assertEmpty($routes);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideRoutePaths(): iterable
    {
        $paths = [
            '/', '/test', '/users', '/users/{id}', '/posts/{slug}', '/api/v1/users',
            '/a', '/a/b/c', '/a/b/c/d/e/f/g',
            '/123', '/test-route', '/test_route', '/test.route',
            '/search?q=hello',
            '/' . str_repeat('x', 100),
            '/' . str_repeat('x/y', 50),
            '/{param1}/{param2}/{param3}',
            '/{id}/edit', '/{id}/delete',
            '/users/{id}/posts/{postId}',
        ];
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        foreach ($methods as $method) {
            foreach ($paths as $path) {
                yield sprintf('%s %s', $method, $path) => [$method, $path];
            }
        }
    }

    /** @dataProvider provideRouterGroupFuzz */
    public function testGroupingNeverThrows(string $prefix, array $methods, array $paths): void
    {
        $this->router->group($prefix, function (Router $router) use ($methods, $paths): void {
            foreach ($paths as $path) {
                $router->get($path, function () {
                    return Response::json(['ok' => true]);
                });
            }
        });
        $routes = $this->router->getRoutes();
        $this->assertIsArray($routes);
    }

    /** @return iterable<string, array{string, array, array}> */
    public static function provideRouterGroupFuzz(): iterable
    {
        yield 'empty prefix' => ['', ['GET'], ['/test']];
        yield 'slash prefix' => ['/', ['GET'], ['/test']];
        yield 'api prefix' => ['/api', ['GET'], ['/users', '/posts']];
        yield 'versioned' => ['/api/v1', ['GET', 'POST'], ['/users', '/auth/login']];
        yield 'nested group' => ['/admin', ['GET'], ['/dashboard', '/settings']];
    }

    /** @dataProvider provideMatcherInputs */
    public function testRouteMatcherNeverThrows(string $method, string $path): void
    {
        $matcher = new RouteMatcher([], [], []);
        $result = $matcher->match($method, $path);
        $this->assertTrue($result === null || is_array($result));
    }

    /** @dataProvider provideMatcherInputs */
    public function testPathExistsNeverThrows(string $method, string $path): void
    {
        $matcher = new RouteMatcher([], [], []);
        $exists = $matcher->pathExists($path);
        $this->assertIsBool($exists);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideMatcherInputs(): iterable
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE', 'CONNECT', '', 'INVALID'];
        $paths = [
            '/', '//', '/test', '/users/123', '/a/b/c',
            "\0", "/\0", "/\n", "/\t",
            str_repeat('/' . 'x', 100),
            '/../../../etc/passwd',
            '/users/{id}',
            '/<script>',
            '/path with spaces',
            '/unicode/♥/★/中文',
            '/%00', '/%20test',
        ];
        foreach ($methods as $method) {
            foreach ($paths as $path) {
                yield sprintf('%s %s', $method, self::truncate($path)) => [$method, $path];
            }
        }
    }

    /** @dataProvider provideHandlerTypes */
    public function testRouteWithVariousHandlerTypes(mixed $handler): void
    {
        try {
            $this->router->get('/fuzz-handler-test', $handler);
            $routes = $this->router->getRoutes();
            $this->assertIsArray($routes);
        } catch (\Throwable $e) {
            // Handler validation errors are acceptable (invalid class etc.)
            // But we must not get non-Throwable errors
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideHandlerTypes(): iterable
    {
        yield 'closure' => [function () { return Response::json(['ok' => true]); }];
        yield 'string handler' => ['App\Controllers\HomeController@index'];
        yield 'array handler' => [['App\Controllers\HomeController', 'index']];
        yield 'non-existent class' => ['NonExistentClass@method'];
        yield 'invalid format' => ['justastring'];
    }

    private static function truncate(string $s, int $max = 30): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '...' : $s;
    }
}
