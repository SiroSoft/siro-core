<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Fluent route configurator AND static facade for route registration.
 *
 * As a facade, provides Laravel-style static methods:
 *   Route::get('/path', $handler)
 *   Route::post('/path', $handler)
 *   etc.
 *
 * As a configurator, allows chaining:
 *   Route::get('/path', $handler)->middleware([...])->cache(60)
 *
 * @package Siro\Core
 */
final class Route
{
    /** @var Router|null */
    private static ?Router $routerInstance = null;

    /** @var array<string, array{method:string, path:string}> */
    private static array $namedRoutes = [];
    
    public function __construct(
        private readonly Router $router,
        private readonly string $method,
        private readonly string $path
    ) {
    }

    /**
     * Set the router instance for the facade
     */
    public static function setRouter(Router $router): void
    {
        self::$routerInstance = $router;
    }

    /**
     * Get the current router instance
     */
    public static function getRouter(): ?Router
    {
        return self::$routerInstance;
    }

    /**
     * Register a GET route (facade method)
     *
     * @param callable|array{0:class-string,1:string}|string $handler
     * @return self
     */
    public static function get(string $path, callable|array|string $handler): self
    {
        return self::registerRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route (facade method)
     *
     * @param callable|array{0:class-string,1:string}|string $handler
     * @return self
     */
    public static function post(string $path, callable|array|string $handler): self
    {
        return self::registerRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route (facade method)
     *
     * @param callable|array{0:class-string,1:string}|string $handler
     * @return self
     */
    public static function put(string $path, callable|array|string $handler): self
    {
        return self::registerRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route (facade method)
     *
     * @param callable|array{0:class-string,1:string}|string $handler
     * @return self
     */
    public static function delete(string $path, callable|array|string $handler): self
    {
        return self::registerRoute('DELETE', $path, $handler);
    }

    /**
     * Register a PATCH route (facade method)
     *
     * @param callable|array{0:class-string,1:string}|string $handler
     * @return self
     */
    public static function patch(string $path, callable|array|string $handler): self
    {
        return self::registerRoute('PATCH', $path, $handler);
    }

    /**
     * Clear all registered routes on the router instance (useful for testing).
     */
    public static function clearRoutes(): void
    {
        if (self::$routerInstance !== null) {
            self::$routerInstance->clearRoutes();
        }
    }

    /**
     * @param array<int, callable|string>|callable|string $middleware
     */
    public function middleware(array|callable|string $middleware): self
    {
        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        $this->router->setRouteMiddleware($this->method, $this->path, $middleware);
        return $this;
    }

    public function cache(int $ttl = 60): self
    {
        $this->router->setRouteCacheTTL($this->method, $this->path, $ttl);
        return $this;
    }

    /**
     * Add regex constraint to route parameter.
     *
     * Usage: Route::get('/users/{id}', ...)->where('id', '[0-9]+')
     *
     * @param array<string, string>|string $name
     */
    public function where(array|string $name, ?string $pattern = null): self
    {
        if (is_array($name)) {
            $this->router->setRouteWhereConstraints($this->method, $this->path, $name);
        } elseif ($pattern !== null) {
            $this->router->setRouteWhereConstraints($this->method, $this->path, [$name => $pattern]);
        }
        return $this;
    }

    public function name(string $name): self
    {
        if ($name !== '') {
            self::$namedRoutes[$name] = [
                'method' => $this->method,
                'path' => $this->path,
            ];
        }
        return $this;
    }

    public static function url(string $name, array $params = []): ?string
    {
        $route = self::$namedRoutes[$name] ?? null;
        if ($route === null) {
            return null;
        }
        $path = $route['path'];
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }
        return $path;
    }

    /**
     * Add rate limiting to route
     * 
     * @param int $maxAttempts Maximum number of attempts
     * @param int $decayMinutes Decay period in minutes
     */
    public function throttle(int $maxAttempts = 60, int $decayMinutes = 1): self
    {
        $this->router->setRouteMiddleware($this->method, $this->path, ['throttle:' . $maxAttempts . ',' . $decayMinutes]);
        return $this;
    }

    /**
     * Register a route with the static router instance
     *
     * @param callable|array{0:class-string,1:string}|string $handler
     * @return self
     */
    /** @param callable|array{0:class-string,1:string}|string $handler */ private static function registerRoute(string $method, string $path, callable|array|string $handler): self
    {
        $router = self::$routerInstance;
        
        if ($router === null) {
            // Auto-create a router if none is set
            $router = new Router();
            self::$routerInstance = $router;
        }
        
        $lowerMethod = strtolower($method);
        $route = $router->{$lowerMethod}($path, $handler);
        
        return $route;
    }
}
