<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;
use RuntimeException;
use Siro\Core\Middleware\JsonMiddleware;
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
            $errors = [];
            $debug = Env::bool('APP_DEBUG', false);
            if ($debug) {
                $suggestion = $this->findSimilarRoute($path);
                $errors['route'] = $suggestion !== null
                    ? ["Route not found: {$path}. Did you mean {$suggestion}?"]
                    : ["Route not found: {$path}"];
            }
            return Response::error('Route not found', 404, $errors);
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

        $response = $this->dispatchWithMiddleware($route['middleware'], $route['handler'], $request);

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

        /** @var mixed $data */
        $data = require $cacheFile;
        if (!is_array($data)) { return false; }
        $staticData = is_array($data['static'] ?? null) ? $data['static'] : [];
        $dynamicData = is_array($data['dynamic'] ?? null) ? $data['dynamic'] : [];
        $storedHmac = is_string($data['hmac'] ?? null) ? $data['hmac'] : '';
        if ($storedHmac === '') { return false; }

        $secret = (string) Env::get('APP_KEY', '');
        $hmacCheck = hash_hmac('sha256', json_encode($staticData) . json_encode($dynamicData), $secret);
        if ($secret !== '' && !hash_equals($hmacCheck, $storedHmac)) {
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

        $secret = (string) Env::get('APP_KEY', '');
        $staticData = $data['static'] ?? [];
        $dynamicData = $data['dynamic'] ?? [];
        $hmac = $secret !== '' ? hash_hmac('sha256', json_encode($staticData) . json_encode($dynamicData), $secret) : '';
        $data['hmac'] = $hmac;
        $exported = var_export($data, true);
        $content = '<?php return ' . $exported . ';' . PHP_EOL;

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
            /** @var string $class */
            $class = $handler[0];
            /** @var string $method */
            $method = $handler[1];

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
     * @return array<int, mixed>
     */
    private function resolveMethodArgs(object $controller, string $method, Request $request): array
    {
        $cacheKey = $controller::class . '@' . $method;
        if (!isset(self::$methodParamCache[$cacheKey])) {
            try {
                $ref = new \ReflectionMethod($controller, $method);
            } catch (\ReflectionException) {
                return [$request];
            }
            self::$methodParamCache[$cacheKey] = $ref->getParameters();
        }
        $params = self::$methodParamCache[$cacheKey];
        return $params === [] ? [] : $this->resolveArgsFromParams($params, $request);
    }

    /**
     * @param \ReflectionParameter[] $params
     * @return array<int, mixed>
     */
    private function resolveArgsFromParams(array $params, Request $request): array
    {
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
        return $params === [] ? [] : $this->resolveArgsFromParams($params, $request);
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

    /**
     * @param array<int, callable|string> $middleware
     * @param callable|array{0: class-string, 1: string}|string $handler
     */
    private function dispatchWithMiddleware(array $middleware, mixed $handler, Request $request): Response
    {
        $middleware = $this->sortMiddlewareByPriority($middleware);
        $pos = 0;
        $next = function (Request $req) use ($middleware, $handler, &$pos, &$next): Response {
            if ($pos >= count($middleware)) {
                /** @var callable|array{0: class-string, 1: string}|string $handler */
                $start = microtime(true);
                $response = $this->runHandler($handler, $req);
                $elapsed = (microtime(true) - $start) * 1000;
                if (class_exists(\Siro\Core\Debug\TraceData::class)) {
                    $handlerName = is_string($handler) ? $handler : 'handler';
                    \Siro\Core\Debug\TraceData::addMiddleware($handlerName, $response->statusCode() < 500, $elapsed);
                }
                return $response;
            }
            /** @var callable|string $mw */
            $mw = $middleware[$pos++];
            $start = microtime(true);
            $response = $this->runMiddleware($mw, $req, $next);
            $elapsed = (microtime(true) - $start) * 1000;
            if (class_exists(\Siro\Core\Debug\TraceData::class)) {
                $mwName = is_string($mw) ? $mw : 'Closure';
                \Siro\Core\Debug\TraceData::addMiddleware($mwName, $response->statusCode() < 500, $elapsed);
            }
            return $response;
        };
        return $next($request);
    }

    /**
     * @param array<int, callable|string> $middleware
     * @return array<int, callable|string>
     */
    private function sortMiddlewareByPriority(array $middleware): array
    {
        if (self::$middlewarePriority === []) {
            return $middleware;
        }

        $prioritized = [];
        $normal = [];

        foreach ($middleware as $mw) {
            $name = is_string($mw) ? strtolower(trim(explode(':', $mw)[0])) : '';
            if ($name !== '' && isset(self::$middlewarePriority[$name])) {
                $prioritized[$name] = ['priority' => self::$middlewarePriority[$name], 'mw' => $mw];
            } else {
                $normal[] = $mw;
            }
        }

        if ($prioritized === []) {
            return $middleware;
        }

        usort($prioritized, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $sorted = array_map(fn(array $item): mixed => $item['mw'], $prioritized);
        return [...$sorted, ...$normal];
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

    /** @var array<string, int> */
    private static array $middlewarePriority = [];

    /** @var array<string, array<int, \ReflectionParameter>> */
    private static array $methodParamCache = [];

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

    public static function setMiddlewarePriority(string $name, int $priority): void
    {
        self::$middlewarePriority[strtolower(trim($name))] = $priority;
    }

    /** @param array<string, int> $priorities */
    public static function setMiddlewarePriorities(array $priorities): void
    {
        foreach ($priorities as $name => $priority) {
            self::$middlewarePriority[strtolower(trim($name))] = $priority;
        }
    }

    /** @return array<string, int> */
    public static function getMiddlewarePriorities(): array
    {
        return self::$middlewarePriority;
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
     * @param array<int, callable|string> $middleware
     */
    public function resource(string $name, string $controller, array $middleware = [], int $cacheTtl = 0): void
    {
        $route = $this->get("/{$name}", $controller . '@index', $middleware);
        if ($cacheTtl > 0) { $route->cache($cacheTtl); }
        $route = $this->get("/{$name}/{id}", $controller . '@show', $middleware);
        if ($cacheTtl > 0) { $route->cache($cacheTtl); }
        $this->post("/{$name}", $controller . '@store', [...$middleware, JsonMiddleware::class]);
        $this->put("/{$name}/{id}", $controller . '@update', [...$middleware, JsonMiddleware::class]);
        $this->delete("/{$name}/{id}", $controller . '@delete', $middleware);
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

    private function findSimilarRoute(string $path): ?string
    {
        $allRoutes = $this->getAllPaths();
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($allRoutes as $existingPath) {
            $dist = levenshtein($path, $existingPath);
            if ($dist < $bestDistance && $dist <= 5) {
                $bestDistance = $dist;
                $bestMatch = $existingPath;
            }
        }

        return $bestMatch;
    }

    /** @return list<string> */
    private function getAllPaths(): array
    {
        $routes = $this->getRoutes();
        $paths = [];
        foreach ($routes as $route) {
            $paths[] = $route['path'];
        }
        return array_values(array_unique($paths));
    }
}
