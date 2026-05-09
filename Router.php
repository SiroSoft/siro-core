<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;
use RuntimeException;

/**
 * HTTP request router and middleware pipeline.
 *
 * Supports static and dynamic routes ({param}), route groups with
 * prefix and middleware inheritance. Middleware uses an onion model
 * pipeline. Automatically handles OPTIONS preflight requests.
 *
 * @package Siro\Core
 */
final class Router
{
    /** @var array<string, array<string, array{path:string,handler:callable|array|string,middleware:array<int, callable|string>,cache_ttl:int}>> */
    private array $staticRoutes = [];
    /** @var array<string, array<int, array{path:string,segments:array<int,string>,handler:callable|array|string,middleware:array<int, callable|string>,cache_ttl:int}>> */
    private array $dynamicRoutes = [];
    /** @var array<string, array<string, string>> */
    private array $whereConstraints = [];
    private string $groupPrefix = '';
    /** @var array<int, callable|string> */
    private array $groupMiddleware = [];
    private bool $routesLoadedFromCache = false;

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
    public function version(int $version, callable $callback): void
    {
        $prefix = "/api/v{$version}";
        $this->group($prefix, [], $callback);
    }

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

        // Auto-handle OPTIONS requests (CORS preflight)
        if ($method === 'OPTIONS') {
            return $this->handleOptionsRequest($path);
        }

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
     * Get all registered routes.
     *
     * @return array<int, array{method:string,path:string,handler:string,middleware:string,cache_ttl:int}>
     */
    public function getRoutes(): array
    {
        $routes = [];

        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $route) {
                $routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'handler' => $this->handlerToString($route['handler']),
                    'middleware' => implode(', ', array_map(fn (mixed $m): string => $this->middlewareToString($m), $route['middleware'])),
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        foreach ($this->dynamicRoutes as $method => $routeList) {
            foreach ($routeList as $route) {
                $routes[] = [
                    'method' => $method,
                    'path' => $route['path'],
                    'handler' => $this->handlerToString($route['handler']),
                    'middleware' => implode(', ', array_map(fn (mixed $m): string => $this->middlewareToString($m), $route['middleware'])),
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        usort($routes, fn (array $a, array $b): int => $a['path'] <=> $b['path'] ?: $a['method'] <=> $b['method']);

        return $routes;
    }

    /**
     * Clear all registered routes. Useful for testing between runs.
     */
    public function clearRoutes(): void
    {
        $this->staticRoutes = [];
        $this->dynamicRoutes = [];
        $this->groupPrefix = '';
        $this->groupMiddleware = [];
    }

    /**
     * Export routes as serializable array for caching.
     *
     * @return array{static:array, dynamic:array}
     */
    public function exportRoutes(): array
    {
        // Clone route data removing callable handlers (keep string/array references)
        $static = [];
        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $route) {
                $static[$method][$path] = [
                    'path' => $route['path'],
                    'handler' => $this->handlerToString($route['handler']),
                    'handler_raw' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        $dynamic = [];
        foreach ($this->dynamicRoutes as $method => $routeList) {
            foreach ($routeList as $route) {
                $dynamic[$method][] = [
                    'path' => $route['path'],
                    'segments' => $route['segments'],
                    'handler' => $this->handlerToString($route['handler']),
                    'handler_raw' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        return ['static' => $static, 'dynamic' => $dynamic];
    }

    public function loadFromCache(string $cacheFile): bool
    {
        if (!is_file($cacheFile)) {
            return false;
        }

        $data = require $cacheFile;
        if (!is_array($data) || !isset($data['static'], $data['dynamic'])) {
            return false;
        }

        $this->staticRoutes = $data['static'];
        $this->dynamicRoutes = $data['dynamic'];
        $this->routesLoadedFromCache = true;
        return true;
    }

    public function saveToCache(string $cacheFile): bool
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $data = $this->exportRoutes();
        $content = '<?php return ' . var_export($data, true) . ';' . PHP_EOL;

        return file_put_contents($cacheFile, $content) !== false;
    }

    public function isCached(): bool
    {
        return $this->routesLoadedFromCache;
    }

    private function handlerToString(callable|array|string $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $class = is_string($handler[0]) ? $handler[0] : $handler[0]::class;
            return $class . '@' . $handler[1];
        }

        if (is_callable($handler)) {
            return 'Closure';
        }

        return 'Unknown';
    }

    private function middlewareToString(callable|string $middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        }

        return 'callable';
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

    /**
     * @param array<string, string> $constraints
     */
    public function setRouteWhereConstraints(string $method, string $path, array $constraints): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $key = $method . ':' . $path;
        $this->whereConstraints[$key] = $constraints;
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

            $controller = $this->resolveController($class);
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

        $controller = $this->resolveController($class);
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

    /** @var array<string, object> */
    private array $resolved = [];

    private function resolveController(string $class): object
    {
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }

        $container = Container::getInstance();

        // Try Container first (supports bindings, singletons, interfaces)
        if ($container->has($class)) {
            $this->resolved[$class] = $container->make($class);
            return $this->resolved[$class];
        }

        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            $this->resolved[$class] = $ref->newInstance();
            return $this->resolved[$class];
        }

        $deps = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $depClass = $type->getName();
                if ($depClass === $class) {
                    $deps[] = null;
                    continue;
                }

                // Check Container first for each dependency
                if ($container->has($depClass)) {
                    $deps[] = $container->make($depClass);
                } else {
                    $deps[] = $this->resolveController($depClass);
                }
            } else {
                $deps[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }
        }

        $this->resolved[$class] = $ref->newInstanceArgs($deps);
        return $this->resolved[$class];
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

            // Apply where constraints
            $constraints = $this->getWhereConstraints($method, $route['path']);
            if ($constraints !== []) {
                foreach ($params as $paramName => $paramValue) {
                    if (isset($constraints[$paramName]) && !preg_match($constraints[$paramName], $paramValue)) {
                        continue 2;
                    }
                }
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

    /**
     * Check if a param value matches its where constraint (if any).
     */
    private function matchesWhereConstraint(string $method, string $path, string $param, string $value): bool
    {
        $key = strtoupper($method) . ':' . $this->normalizePath($path);
        $constraints = $this->whereConstraints[$key] ?? [];
        if (!isset($constraints[$param])) {
            return true;
        }
        return preg_match($constraints[$param], $value) === 1;
    }

    private function getWhereConstraints(string $method, string $path): array
    {
        $key = strtoupper($method) . ':' . $this->normalizePath($path);
        return $this->whereConstraints[$key] ?? [];
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
            $rawParams = $paramString === '' ? [] : array_map('trim', explode(',', $paramString));
            $params = array_map(function (string $p): mixed {
                if (is_numeric($p)) {
                    return str_contains($p, '.') ? (float) $p : (int) $p;
                }
                $lower = strtolower($p);
                if ($lower === 'true') return true;
                if ($lower === 'false') return false;
                if ($lower === 'null') return null;
                return $p;
            }, $rawParams);
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

    /** @var array<string, string> Registered middleware aliases */
    private static array $middlewareAliases = [];

    /**
     * Register a middleware alias.
     *
     * Usage in app bootstrap:
     *   Router::setMiddlewareAliases([
     *       'auth' => \App\Middleware\AuthMiddleware::class,
     *       'throttle' => \App\Middleware\ThrottleMiddleware::class,
     *   ]);
     *
     * @param array<string, string> $aliases
     */
    public static function setMiddlewareAliases(array $aliases): void
    {
        foreach ($aliases as $name => $class) {
            self::$middlewareAliases[strtolower(trim($name))] = $class;
        }
    }

    /**
     * Register a single middleware alias.
     */
    public static function registerMiddlewareAlias(string $name, string $class): void
    {
        self::$middlewareAliases[strtolower(trim($name))] = $class;
    }

    /** @return array<string, string> */
    public static function getMiddlewareAliases(): array
    {
        return self::$middlewareAliases;
    }

    private function resolveMiddlewareAlias(string $name): string
    {
        $normalized = strtolower(trim($name));
        return self::$middlewareAliases[$normalized] ?? $name;
    }

    /**
     * Handle OPTIONS requests automatically (CORS preflight).
     * Returns 204 No Content with appropriate CORS headers.
     */
    private function handleOptionsRequest(string $path): Response
    {
        // Check if there are any routes for this path (any method)
        $hasRoute = false;
        
        // Check static routes
        foreach ($this->staticRoutes as $routes) {
            if (isset($routes[$path])) {
                $hasRoute = true;
                break;
            }
        }

        // Check dynamic routes
        if (!$hasRoute) {
            foreach ($this->dynamicRoutes as $routes) {
                foreach ($routes as $route) {
                    $params = $this->matchSegments($route['segments'], $this->splitSegments($path));
                    if ($params !== null) {
                        $hasRoute = true;
                        break 2;
                    }
                }
            }
        }

        if (!$hasRoute) {
            return Response::error('Route not found', 404);
        }

        $allowedOrigins = (string) \Siro\Core\Env::get('CORS_ALLOWED_ORIGINS', '*');
        $allowedMethods = (string) \Siro\Core\Env::get('CORS_ALLOWED_METHODS', 'GET,POST,PUT,DELETE,OPTIONS');
        $allowedHeaders = (string) \Siro\Core\Env::get('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With');
        $parts = explode(',', $allowedOrigins);
        $origin = $allowedOrigins === '*' ? '*' : $parts[0];
        $allowCredentials = $allowedOrigins !== '*' ? 'true' : 'false';

        return Response::noContent()
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Methods', $allowedMethods)
            ->header('Access-Control-Allow-Headers', $allowedHeaders)
            ->header('Access-Control-Allow-Credentials', $allowCredentials)
            ->header('Access-Control-Max-Age', '86400');
    }
}
