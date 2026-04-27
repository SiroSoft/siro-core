<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;
use RuntimeException;

final class Router
{
    /** @var array<string, array<string, array{path:string,handler:callable|array|string,middleware:array<int, callable|string>,cache_ttl:int}>> */
    private array $staticRoutes = [];
    /** @var array<string, array<int, array{path:string,segments:array<int,string>,handler:callable|array|string,middleware:array<int, callable|string>,cache_ttl:int}>> */
    private array $dynamicRoutes = [];
    private string $groupPrefix = '';
    /** @var array<int, callable|string> */
    private array $groupMiddleware = [];

    /** @param array<int, callable|string> $middleware */
    public function get(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    /** @param array<int, callable|string> $middleware */
    public function post(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    /** @param array<int, callable|string> $middleware */
    public function put(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param array<int, callable|string> $middleware */
    public function delete(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param array<int, callable|string> $middleware */
    public function options(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add('OPTIONS', $path, $handler, $middleware);
    }

    /**
     * Supports:
     * - group('/api', function($router) {}, [Middleware::class])
     * - group('/api', [Middleware::class], function($router) {})
     */
    public function group(string $prefix, callable|array $arg2, callable|array|null $arg3 = null): void
    {
        $callback = null;
        $middleware = [];

        if (is_callable($arg2)) {
            $callback = $arg2;
            $middleware = is_array($arg3) ? $arg3 : [];
        } else {
            $middleware = $arg2;
            $callback = is_callable($arg3) ? $arg3 : null;
        }

        if ($callback === null) {
            throw new RuntimeException('Group callback is required.');
        }

        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $this->normalizePath($previousPrefix . '/' . trim($prefix, '/'));
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        $route = $this->staticRoutes[$method][$path] ?? null;
        if ($route === null) {
            $route = $this->matchDynamicRoute($method, $path);
        }

        if ($route === null) {
            return Response::error('Route not found', 404);
        }

        if (isset($route['params']) && is_array($route['params'])) {
            $request->setParams($route['params']);
        }

        $cacheTtl = (int) ($route['cache_ttl'] ?? 0);
        $canUseCache = $method === 'GET' && $cacheTtl > 0;
        if ($canUseCache) {
            $cacheKey = 'route:' . $request->cacheKey();
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['payload']) && isset($cached['status'])) {
                return Response::json(
                    is_array($cached['payload']) ? $cached['payload'] : [],
                    (int) $cached['status']
                );
            }
        }

        $finalHandler = function (Request $req) use ($route): Response {
            return $this->runHandler($route['handler'], $req);
        };

        $pipeline = array_reverse($route['middleware']);
        foreach ($pipeline as $middleware) {
            $next = $finalHandler;
            $finalHandler = function (Request $req) use ($middleware, $next): Response {
                return $this->runMiddleware($middleware, $req, $next);
            };
        }

        $response = $finalHandler($request);

        if ($canUseCache) {
            Cache::set($cacheKey, [
                'payload' => $response->payload(),
                'status' => $response->statusCode(),
            ], $cacheTtl);
        }

        return $response;
    }

    /**
     * @param array<int, callable|string> $middleware
     */
    private function add(string $method, string $path, callable|array|string $handler, array $middleware = []): Route
    {
        $method = strtoupper($method);
        $fullPath = $this->normalizePath($this->groupPrefix . '/' . trim($path, '/'));
        $routeData = [
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
            'cache_ttl' => 0,
        ];

        if ($this->isDynamicPath($fullPath)) {
            $this->dynamicRoutes[$method][] = [
                ...$routeData,
                'segments' => $this->splitSegments($fullPath),
            ];
        } else {
            $this->staticRoutes[$method][$fullPath] = $routeData;
        }

        return new Route($this, $method, $fullPath);
    }

    /**
     * @param array<int, callable|string> $middleware
     */
    public function setRouteMiddleware(string $method, string $path, array $middleware): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);

        if (isset($this->staticRoutes[$method][$path])) {
            $this->staticRoutes[$method][$path]['middleware'] = [
                ...$this->staticRoutes[$method][$path]['middleware'],
                ...$middleware,
            ];
            return;
        }

        if (!isset($this->dynamicRoutes[$method])) {
            return;
        }

        foreach ($this->dynamicRoutes[$method] as $index => $route) {
            if ($route['path'] !== $path) {
                continue;
            }

            $this->dynamicRoutes[$method][$index]['middleware'] = [
                ...$route['middleware'],
                ...$middleware,
            ];
            return;
        }
    }

    public function setRouteCacheTTL(string $method, string $path, int $ttl): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $ttl = max(0, $ttl);

        if (isset($this->staticRoutes[$method][$path])) {
            $this->staticRoutes[$method][$path]['cache_ttl'] = $ttl;
            return;
        }

        if (!isset($this->dynamicRoutes[$method])) {
            return;
        }

        foreach ($this->dynamicRoutes[$method] as $index => $route) {
            if ($route['path'] !== $path) {
                continue;
            }

            $this->dynamicRoutes[$method][$index]['cache_ttl'] = $ttl;
            return;
        }
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '/.' || $normalized === '' ? '/' : $normalized;
    }

    private function runHandler(callable|array|string $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            try {
                $response = $handler($request);
            } catch (\ArgumentCountError) {
                $response = $handler();
            }
            return $this->normalizeHandlerResult($response);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (!is_string($class) || !is_string($method)) {
                throw new RuntimeException('Invalid array handler format. Use [ClassName::class, method].');
            }

            $controller = new $class();
            if (!method_exists($controller, $method)) {
                throw new RuntimeException(sprintf('Method %s::%s not found.', $class, $method));
            }

            try {
                return $this->normalizeHandlerResult($controller->{$method}($request));
            } catch (\ArgumentCountError) {
                return $this->normalizeHandlerResult($controller->{$method}());
            }
        }

        [$class, $method] = explode('@', $handler, 2) + [null, null];
        if ($class === null || $method === null) {
            throw new RuntimeException('Invalid route handler format. Use Class@method.');
        }

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('Controller class %s not found.', $class));
        }

        $controller = new $class();
        if (!method_exists($controller, $method)) {
            throw new RuntimeException(sprintf('Method %s::%s not found.', $class, $method));
        }

        try {
            $response = $controller->{$method}($request);
        } catch (\ArgumentCountError) {
            $response = $controller->{$method}();
        }
        return $this->normalizeHandlerResult($response);
    }

    private function normalizeHandlerResult(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        throw new RuntimeException('Route handler result must be Response|array|null.');
    }

    /** @return array{path:string,handler:callable|array|string,middleware:array<int, callable|string>,cache_ttl:int,params?:array<string,string>}|null */
    private function matchDynamicRoute(string $method, string $path): ?array
    {
        $routes = $this->dynamicRoutes[$method] ?? [];
        if ($routes === []) {
            return null;
        }

        $pathSegments = $this->splitSegments($path);

        foreach ($routes as $route) {
            $params = $this->matchSegments($route['segments'], $pathSegments);
            if ($params === null) {
                continue;
            }

            return [
                'path' => $route['path'],
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'cache_ttl' => $route['cache_ttl'],
                'params' => $params,
            ];
        }

        return null;
    }

    /** @return array<int, string> */
    private function splitSegments(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        return explode('/', $trimmed);
    }

    /**
     * @param array<int, string> $routeSegments
     * @param array<int, string> $pathSegments
     * @return array<string, string>|null
     */
    private function matchSegments(array $routeSegments, array $pathSegments): ?array
    {
        if (count($routeSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];

        foreach ($routeSegments as $index => $routeSegment) {
            $pathSegment = $pathSegments[$index];

            if ($this->isParamSegment($routeSegment)) {
                $paramName = substr($routeSegment, 1, -1);
                if ($paramName === '') {
                    return null;
                }
                $params[$paramName] = $pathSegment;
                continue;
            }

            if ($routeSegment !== $pathSegment) {
                return null;
            }
        }

        return $params;
    }

    private function isDynamicPath(string $path): bool
    {
        return str_contains($path, '{') && str_contains($path, '}');
    }

    private function isParamSegment(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }

    /**
     * @param callable|string $middleware
     * @param Closure(Request): Response $next
     */
    private function runMiddleware(callable|string $middleware, Request $request, Closure $next): Response
    {
        if (is_callable($middleware)) {
            return $this->normalizeHandlerResult($middleware($request, $next));
        }

        $params = [];
        $middlewareClass = $middleware;

        if (str_contains($middleware, ':')) {
            [$name, $paramString] = explode(':', $middleware, 2);
            $middlewareClass = $this->resolveMiddlewareAlias(trim($name));
            $params = $paramString === '' ? [] : array_map('trim', explode(',', $paramString));
        } else {
            $middlewareClass = $this->resolveMiddlewareAlias($middleware);
        }

        if (!class_exists($middlewareClass)) {
            throw new RuntimeException(sprintf('Middleware class %s not found.', $middleware));
        }

        $instance = new $middlewareClass();
        if (!method_exists($instance, 'handle')) {
            throw new RuntimeException(sprintf('Middleware %s must have handle() method.', $middlewareClass));
        }

        if ($params === []) {
            return $this->normalizeHandlerResult($instance->handle($request, $next));
        }

        return $this->normalizeHandlerResult($instance->handle($request, $next, ...$params));
    }

    private function resolveMiddlewareAlias(string $name): string
    {
        $normalized = strtolower(trim($name));

        // Return fully qualified class names as strings - these will be resolved by the app
        return match ($normalized) {
            'auth' => '\App\Middleware\AuthMiddleware',
            'throttle' => '\App\Middleware\ThrottleMiddleware',
            'cors' => '\App\Middleware\CorsMiddleware',
            'json' => '\App\Middleware\JsonMiddleware',
            default => $name,
        };
    }
}
