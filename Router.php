<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;
use RuntimeException;
use Siro\Core\Middleware\MiddlewareInterface;

final class Router
{
    private RouteMatcher $matcher;
    private string $groupPrefix = '';
    /** @var array<int, callable|string> */
    private array $groupMiddleware = [];
    private bool $routesLoadedFromCache = false;
    private bool $matcherDirty = false;

    public function __construct()
    {
        $this->matcher = new RouteMatcher([], [], []);
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    public function get(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add(Method::GET->value, $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    public function post(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add(Method::POST->value, $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    public function put(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add(Method::PUT->value, $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    public function delete(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add(Method::DELETE->value, $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    public function patch(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add(Method::PATCH->value, $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    public function options(string $path, callable|array|string $handler, array $middleware = []): Route
    {
        return $this->add(Method::OPTIONS->value, $path, $handler, $middleware);
    }

    public function version(int $version, callable $callback): void
    {
        $prefix = "/api/v{$version}";
        $this->group($prefix, [], $callback);
    }

    /**
     * @param callable|array<int, callable|string> $arg2
     * @param callable|array<int, callable|string>|null $arg3
     */
    public function group(string $prefix, callable|array $arg2, callable|array|null $arg3 = null): void
    {
        $callback = null;
        /** @var array<int, callable|string> $middleware */
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

        $this->groupPrefix = RouteMatcher::normalizePath($previousPrefix . '/' . trim($prefix, '/'));
        /** @var array<int, callable|string> $middleware */
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /** @var array<string, array<string, array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int, callable|string>,cache_ttl:int}>> */
    private array $staticRoutes = [];
    /** @var array<string, array<int, array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int, callable|string>,cache_ttl:int}>> */
    private array $dynamicRoutes = [];
    /** @var array<string, array<string, string>> */
    private array $whereConstraints = [];

    private function rebuildMatcher(): void
    {
        $this->matcher = new RouteMatcher($this->dynamicRoutes, $this->staticRoutes, $this->whereConstraints);
        $this->matcherDirty = false;
    }

    public function dispatch(Request $request): Response
    {
        if ($this->matcherDirty) {
            $this->rebuildMatcher();
        }
        $method = $request->method();
        $path = $request->path();

        if ($method === 'OPTIONS') {
            return $this->handleOptionsRequest($path);
        }

        $route = $this->matcher->match($method, $path);

        if ($route === null) {
            return Response::error('Route not found', 404);
        }

        if (isset($route['params'])) {
            $request->setParams($route['params']);
        }

        $cacheTtl = $route['cache_ttl'];
        $canUseCache = $method === 'GET' && $cacheTtl > 0;
        $cacheKey = '';
        if ($canUseCache) {
            $cacheKey = 'route:' . $request->cacheKey();
            $cached = Cache::get($cacheKey);

                if (is_array($cached) && isset($cached['payload'], $cached['status'], $cached['headers'])) {
                /** @var int $status */
                $status = $cached['status'];
                /** @var array<string, mixed> $payload */
                $payload = is_array($cached['payload']) ? $cached['payload'] : [];
                $response = Response::json(
                    $payload,
                    $status
                );
                if (is_array($cached['headers'])) {
                    /** @var array<string, string> $headers */
                    $headers = $cached['headers'];
                    foreach ($headers as $name => $value) {
                        $response->header($name, $value);
                    }
                }
                return $response;
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
                'headers' => $response->headers(),
            ], $cacheTtl);
        }

        return $response;
    }

    /**
     * @return array<int, array{method:string,path:string,handler:string,middleware:string,cache_ttl:int}>
     */
    public function getRoutes(): array
    {
        if ($this->matcherDirty) {
            $this->rebuildMatcher();
        }
        return $this->matcher->getRoutes();
    }

    public function clearRoutes(): void
    {
        $this->staticRoutes = [];
        $this->dynamicRoutes = [];
        $this->whereConstraints = [];
        $this->groupPrefix = '';
        $this->groupMiddleware = [];
        $this->rebuildMatcher();
    }

    /**
     * @return array{static:array<string,array<string,array{path:string,handler:string,handler_raw:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>,dynamic:array<string,array<int,array{path:string,segments:array<int,string>,handler:string,handler_raw:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>}
     */
    public function exportRoutes(): array
    {
        if ($this->matcherDirty) {
            $this->rebuildMatcher();
        }
        return $this->matcher->export();
    }

    public function loadFromCache(string $cacheFile): bool
    {
        if (!is_file($cacheFile)) {
            return false;
        }

        $raw = (string) file_get_contents($cacheFile);
        $payload = substr($raw, strlen('<?php exit; ?>'));
        $sep = strrpos($payload, '.hmac.');
        if ($sep === false) {
            return false;
        }
        $json = substr($payload, 0, $sep);
        $hmac = trim(substr($payload, $sep + 6));
        $secret = (string) Env::get('JWT_SECRET', '');
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $json, $secret), $hmac)) {
            return false;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['static'], $data['dynamic'])) {
            return false;
        }

        /** @var array{static:array<string,array<string,array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>,dynamic:array<string,array<int,array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>} $data */
        $this->staticRoutes = $data['static'];
        $this->dynamicRoutes = $data['dynamic'];
        $this->rebuildMatcher();
        $this->routesLoadedFromCache = true;
        return true;
    }

    public function saveToCache(string $cacheFile): bool
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if ($this->matcherDirty) {
            $this->rebuildMatcher();
        }

        /** @var array<string, array<string, array<int, array<string, mixed>>>> $data */
        $data = $this->matcher->export();

        foreach (['static', 'dynamic'] as $type) {
            foreach ($data[$type] ?? [] as $method => &$routes) {
                if ($type === 'static') {
                    foreach ($routes as $path => $route) {
                        if ($route['handler'] === 'Closure') {
                            unset($routes[$path]);
                        }
                    }
                } else {
                    $routes = array_values(array_filter($routes, fn($r) => $r['handler'] !== 'Closure'));
                }
            }
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { return false; }
        $secret = (string) Env::get('JWT_SECRET', '');
        $hmac = $secret !== '' ? hash_hmac('sha256', $json, $secret) : '';
        $content = '<?php exit; ?>' . $json . '.hmac.' . $hmac . PHP_EOL;

        return file_put_contents($cacheFile, $content) !== false;
    }

    public function isCached(): bool
    {
        return $this->routesLoadedFromCache;
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     * @param array<int, callable|string> $middleware
     */
    private function add(string $method, string $path, callable|array|string $handler, array $middleware = []): Route
    {
        $method = strtoupper($method);
        $fullPath = RouteMatcher::normalizePath($this->groupPrefix . '/' . trim($path, '/'));
        /** @var array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $routeData */
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

        $this->matcherDirty = true;

        return new Route($this, $method, $fullPath);
    }

    /**
     * @param array<int, callable|string> $middleware
     */
    public function setRouteMiddleware(string $method, string $path, array $middleware): void
    {
        $method = strtoupper($method);
        $path = RouteMatcher::normalizePath($path);

        if (isset($this->staticRoutes[$method][$path])) {
            $this->staticRoutes[$method][$path]['middleware'] = [
                ...$this->staticRoutes[$method][$path]['middleware'],
                ...$middleware,
            ];
            $this->rebuildMatcher();
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
            $this->rebuildMatcher();
            return;
        }
    }

    /**
     * @param array<string, string> $constraints
     */
    public function setRouteWhereConstraints(string $method, string $path, array $constraints): void
    {
        $method = strtoupper($method);
        $path = RouteMatcher::normalizePath($path);
        $key = $method . ':' . $path;
        $this->whereConstraints[$key] = $constraints;
        $this->rebuildMatcher();
    }

    public function setRouteCacheTTL(string $method, string $path, int $ttl): void
    {
        $method = strtoupper($method);
        $path = RouteMatcher::normalizePath($path);
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

    /** @param callable|array{0:class-string,1:string}|string $handler */
    private function runHandler(callable|array|string $handler, Request $request): Response
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2) + [null, null];
            if ($class === null || $method === null) {
                throw new RuntimeException('Invalid route handler format. Use Class@method.');
            }
            $handler = [$class, $method];
        }

        if (is_array($handler)) {
            [$class, $method] = $handler;
            if (!is_string($class) || !is_string($method)) {
                throw new RuntimeException('Invalid route handler format. Expected [className, methodName].');
            }

            $controller = $this->resolveController($class);
            if (!method_exists($controller, $method)) {
                throw new RuntimeException(sprintf('Method %s::%s not found.', $class, $method));
            }

            if ($controller instanceof Controller) {
                $controller->setRequest($request);
            }

            $resolved = $this->resolveMethodArgs($controller, $method, $request);
            return $this->normalizeHandlerResult($controller->{$method}(...$resolved));
        }

        if (is_callable($handler)) {
            $resolved = $this->resolveCallableArgs($handler, $request);
            return $this->normalizeHandlerResult($handler(...$resolved));
        }

        throw new RuntimeException('Invalid route handler format.');
    }

    /**
     * Resolve method arguments using reflection.
     * Auto-resolves Request and FormRequest type-hints.
     *
     * @return array<int, mixed>
     */
    private function resolveMethodArgs(object $controller, string $method, Request $request): array
    {
        try {
            $ref = new \ReflectionMethod($controller, $method);
        } catch (\ReflectionException) {
            return [$request];
        }

        $params = $ref->getParameters();
        if ($params === []) {
            return [];
        }

        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();

                if ($typeName === Request::class) {
                    $args[] = $request;
                    continue;
                }

                if ($typeName === FormRequest::class || is_subclass_of($typeName, FormRequest::class)) {
                    $instance = new $typeName($request);
                    if ($instance->fails()) {
                        throw new ValidationException($instance->errors());
                    }
                    $args[] = $instance;
                    continue;
                }

                if ($type->allowsNull() && $param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            }
        }

        return $args;
    }

    /**
     * Resolve arguments for callable handlers.
     *
     * @return array<int, mixed>
     */
    private function resolveCallableArgs(callable $handler, Request $request): array
    {
        try {
            $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        } catch (\ReflectionException) {
            return [$request];
        }

        $params = $ref->getParameters();
        if ($params === []) {
            return [];
        }

        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();

                if ($typeName === Request::class) {
                    $args[] = $request;
                    continue;
                }

                if ($typeName === FormRequest::class || is_subclass_of($typeName, FormRequest::class)) {
                    $instance = new $typeName($request);
                    if ($instance->fails()) {
                        throw new ValidationException($instance->errors());
                    }
                    $args[] = $instance;
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            }
        }

        return $args;
    }

    /** @var array<string, object> */
    private array $resolved = [];

    private function resolveController(string $class): object
    {
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }

        $this->resolved[$class] = Container::getInstance()->make($class);
        return $this->resolved[$class];
    }

    private function normalizeHandlerResult(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            /** @var array<string, mixed> $result */
            return Response::json($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        throw new RuntimeException('Route handler result must be Response|array|null.');
    }

    /**
     * @return array<int, string>
     */
    private function splitSegments(string $path): array
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    private function isDynamicPath(string $path): bool
    {
        return str_contains($path, '{') && str_contains($path, '}');
    }

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
            $rawParams = $paramString === '' ? [] : array_map('trim', explode(',', (string) $paramString));
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
        if (!$instance instanceof MiddlewareInterface) {
            throw new RuntimeException(sprintf('Middleware %s must implement MiddlewareInterface.', $middlewareClass));
        }

        if ($params === []) {
            return $this->normalizeHandlerResult($instance->handle($request, $next));
        }

        return $this->normalizeHandlerResult($instance->handle($request, $next, ...$params));
    }

    /** @var array<string, string> */
    private static array $middlewareAliases = [];

    /**
     * @param array<string, string> $aliases
     */
    public static function setMiddlewareAliases(array $aliases): void
    {
        foreach ($aliases as $name => $class) {
            self::$middlewareAliases[strtolower(trim($name))] = $class;
        }
    }

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

    private function handleOptionsRequest(string $path): Response
    {
        if (!$this->matcher->pathExists($path)) {
            return Response::error('Route not found', 404);
        }

        $finalHandler = function (Request $req): Response {
            return Response::noContent()
                ->header('Access-Control-Max-Age', '86400');
        };

        $pipeline = array_reverse($this->groupMiddleware);
        foreach ($pipeline as $middleware) {
            $next = $finalHandler;
            $finalHandler = function (Request $req) use ($middleware, $next): Response {
                return $this->runMiddleware($middleware, $req, $next);
            };
        }

        /** @var array<string, string> $reqHeaders */
        $reqHeaders = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $req = new Request('OPTIONS', $path, $_GET, $reqHeaders, []);
        return $finalHandler($req);
    }
}
