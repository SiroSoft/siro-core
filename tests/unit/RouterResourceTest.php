<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Route;
use Siro\Core\Router;
use Siro\Core\Tests\TestCase;

/**
 * Chainable Route::resource() with only/except (Laravel parity).
 */
final class RouterResourceTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        Route::setRouter($this->router);
        Route::clearRoutes();
    }

    protected function tearDown(): void
    {
        Route::clearRoutes();
        Route::resetNamedRoutes();
        Router::resetStatic();
        parent::tearDown();
    }

    public function testResourceRegistersAllFiveActions(): void
    {
        $routes = $this->router->resource('products', 'ProductController');

        $this->assertSame(['index', 'show', 'store', 'update', 'delete'], array_keys($routes));
        $this->assertCount(5, $this->router->getRoutes());
    }

    public function testResourceOnly(): void
    {
        $routes = $this->router->resource('products', 'ProductController', [], 0, ['index', 'show']);

        $this->assertSame(['index', 'show'], array_keys($routes));
        $this->assertCount(2, $this->router->getRoutes());
    }

    public function testResourceExcept(): void
    {
        $routes = $this->router->resource('products', 'ProductController', [], 0, [], ['delete']);

        $this->assertArrayNotHasKey('delete', $routes);
        $this->assertCount(4, $this->router->getRoutes());
    }

    public function testResourceRoutesAreChainable(): void
    {
        $routes = $this->router->resource('products', 'ProductController');
        $routes['store']->middleware('permission:products.create');

        $all = $this->router->getRoutes();
        $store = null;
        foreach ($all as $route) {
            if ($route['path'] === '/products' && str_contains($route['handler'], 'store')) {
                $store = $route;
            }
        }
        $this->assertNotNull($store);
        $this->assertStringContainsString('permission:products.create', $store['middleware']);
    }

    public function testResourceFacade(): void
    {
        $routes = Route::resource('orders', 'OrderController', [], 0, ['index']);

        $this->assertSame(['index'], array_keys($routes));
    }
}
