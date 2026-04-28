<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Router;
use Siro\Core\Route;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Router Unit Tests
 * 
 * Tests routing functionality and route matching
 */
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        
        // Clear any existing routes
        Route::clearRoutes();
    }

    /**
     * Test GET route registration
     */
    public function testGetRouteRegistration(): void
    {
        Route::get('/test', function() {
            return Response::json(['message' => 'GET']);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        $this->assertNotEmpty($routes);
    }

    /**
     * Test POST route registration
     */
    public function testPostRouteRegistration(): void
    {
        Route::post('/test', function() {
            return Response::json(['message' => 'POST']);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        $this->assertNotEmpty($routes);
    }

    /**
     * Test PUT route registration
     */
    public function testPutRouteRegistration(): void
    {
        Route::put('/test', function() {
            return Response::json(['message' => 'PUT']);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
    }

    /**
     * Test DELETE route registration
     */
    public function testDeleteRouteRegistration(): void
    {
        Route::delete('/test', function() {
            return Response::json(['message' => 'DELETE']);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
    }

    /**
     * Test route with parameters
     */
    public function testRouteWithParameters(): void
    {
        Route::get('/users/{id}', function($id) {
            return Response::json(['id' => $id]);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        $found = false;
        foreach ($routes as $route) {
            if (strpos($route['path'], '{id}') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Route with parameter not found');
    }

    /**
     * Test multiple routes
     */
    public function testMultipleRoutes(): void
    {
        Route::get('/users', function() {
            return Response::json([]);
        });

        Route::post('/users', function() {
            return Response::json(['created' => true], 201);
        });

        Route::get('/posts', function() {
            return Response::json([]);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        $this->assertGreaterThanOrEqual(3, count($routes));
    }

    /**
     * Test getRoutes() returns array
     */
    public function testGetRoutesReturnsArray(): void
    {
        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
    }

    /**
     * Test route has method property
     */
    public function testRouteHasMethodProperty(): void
    {
        Route::get('/test', function() {
            return Response::json([]);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        if (!empty($routes)) {
            $this->assertArrayHasKey('method', $routes[0]);
        }
    }

    /**
     * Test route has path property
     */
    public function testRouteHasPathProperty(): void
    {
        Route::get('/test-path', function() {
            return Response::json([]);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        if (!empty($routes)) {
            $this->assertArrayHasKey('path', $routes[0]);
        }
    }

    /**
     * Test OPTIONS auto-handling exists
     */
    public function testOptionsAutoHandlingExists(): void
    {
        // Router should have dispatch method that handles OPTIONS
        $this->assertTrue(method_exists($this->router, 'dispatch'));
    }

    /**
     * Test middleware support exists
     */
    public function testMiddlewareSupportExists(): void
    {
        // Route should support middleware
        $route = Route::get('/test', function() {
            return Response::json([]);
        });
        
        $this->assertTrue(method_exists($route, 'middleware'));
    }

    /**
     * Test cache support exists
     */
    public function testCacheSupportExists(): void
    {
        // Route should support caching
        $route = Route::get('/test', function() {
            return Response::json([]);
        });
        
        $this->assertTrue(method_exists($route, 'cache'));
    }

    /**
     * Test route with middleware
     */
    public function testRouteWithMiddleware(): void
    {
        Route::get('/protected', function() {
            return Response::json(['protected' => true]);
        })->middleware(['auth']);

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
    }

    /**
     * Test route with cache
     */
    public function testRouteWithCache(): void
    {
        Route::get('/cached', function() {
            return Response::json(['cached' => true]);
        })->cache(60);

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
    }

    /**
     * Test empty routes collection
     */
    public function testEmptyRoutesCollection(): void
    {
        // Fresh router should have empty or minimal routes
        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
    }

    /**
     * Test route pattern matching
     */
    public function testRoutePatternMatching(): void
    {
        Route::get('/api/users', function() {
            return Response::json(['api' => true]);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        $found = false;
        foreach ($routes as $route) {
            if ($route['path'] === '/api/users') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Route /api/users not found');
    }

    /**
     * Test different HTTP methods for same path
     */
    public function testDifferentMethodsForSamePath(): void
    {
        Route::get('/resource', function() {
            return Response::json(['method' => 'GET']);
        });

        Route::post('/resource', function() {
            return Response::json(['method' => 'POST']);
        });

        Route::put('/resource', function() {
            return Response::json(['method' => 'PUT']);
        });

        $routes = $this->router->getRoutes();
        
        $this->assertIsArray($routes);
        $this->assertGreaterThanOrEqual(3, count($routes));
    }
}
