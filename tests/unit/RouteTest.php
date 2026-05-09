<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Route;
use Siro\Core\Router;

final class RouteTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        Route::setRouter($this->router);
    }

    protected function tearDown(): void
    {
        Route::clearRoutes();
    }

    public function testRouteStaticGet(): void
    {
        $route = Route::get('/test', fn() => 'ok');
        $this->assertInstanceOf(Route::class, $route);
    }

    public function testRouteStaticPost(): void
    {
        $route = Route::post('/test', fn() => 'ok');
        $this->assertInstanceOf(Route::class, $route);
    }

    public function testRouteStaticPut(): void
    {
        $route = Route::put('/test', fn() => 'ok');
        $this->assertInstanceOf(Route::class, $route);
    }

    public function testRouteStaticDelete(): void
    {
        $route = Route::delete('/test', fn() => 'ok');
        $this->assertInstanceOf(Route::class, $route);
    }

    public function testRouteMiddlewareChain(): void
    {
        Route::get('/secure', fn() => 'ok')->middleware(['auth', 'throttle:60,1']);
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouteCacheChain(): void
    {
        Route::get('/cached', fn() => 'ok')->cache(60);
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouteWhereChain(): void
    {
        Route::get('/users/{id}', fn() => 'ok')->where('id', '/^\d+$/');
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouteThrottleChain(): void
    {
        Route::post('/api/login', fn() => 'ok')->throttle(5, 1);
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouteFullChain(): void
    {
        Route::post('/api/data', fn() => 'ok')
            ->middleware(['cors', 'json'])
            ->cache(30)
            ->throttle(100, 1);

        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouteAutoCreatesRouter(): void
    {
        Route::clearRoutes();
        $route = Route::get('/auto', fn() => 'ok');
        $this->assertInstanceOf(Route::class, $route);
    }

    public function testRouteStringHandler(): void
    {
        Route::get('/str', 'Controller@method');
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouteArrayHandler(): void
    {
        Route::get('/arr', ['Controller', 'method']);
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testMultipleRoutesRegistered(): void
    {
        Route::get('/a', fn() => 'a');
        Route::post('/b', fn() => 'b');
        Route::put('/c', fn() => 'c');
        Route::delete('/d', fn() => 'd');
        $routes = $this->router->getRoutes();
        $this->assertCount(4, $routes);
    }
}