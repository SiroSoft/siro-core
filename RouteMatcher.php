<?php

declare(strict_types=1);

namespace Siro\Core;

final class RouteMatcher
{
    /** @var array<string, array<string, array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>> */
    private array $staticRoutes;
    /** @var array<string, array<int, array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>> */
    private array $dynamicRoutes;
    /** @var array<string, array<string, string>> */
    private array $whereConstraints;

    /** @var array<string, array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int,params?:array<string,string>}|null> */
    private array $matchCache = [];

    /** @var array{}|array{static:array<string,array<string,array{path:string,handler:string,handler_raw:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>,dynamic:array<string,array<int,array{path:string,segments:array<int,string>,handler:string,handler_raw:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>} */
    private array $exportCache = [];
    /** @var array<int, array{method:string,path:string,handler:string,middleware:string,cache_ttl:int}>|null */
    private ?array $routeListCache = null;

    /**
     * @param array<string, array<string, array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>> $staticRoutes
     * @param array<string, array<int, array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>> $dynamicRoutes
     * @param array<string, array<string, string>> $whereConstraints
     */
    public function __construct(
        array $dynamicRoutes,
        array $staticRoutes,
        array $whereConstraints,
    ) {
        $this->staticRoutes = $staticRoutes;
        $this->dynamicRoutes = $dynamicRoutes;
        $this->whereConstraints = $whereConstraints;
    }

    public function markDirty(): void
    {
        $this->matchCache = [];
        $this->routeListCache = null;
        $this->exportCache = [];
    }

    /**
     * @return array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int,params?:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $cacheKey = $method . ':' . $path;
        if (array_key_exists($cacheKey, $this->matchCache)) {
            return $this->matchCache[$cacheKey];
        }

        $route = $this->staticRoutes[$method][$path] ?? null;
        if ($route !== null) {
            $this->matchCache[$cacheKey] = $route;
            return $route;
        }

        $result = $this->linearMatch($method, $this->splitSegments($path));
        $this->matchCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * @param array<int, string> $pathSegments
     * @return array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int,params?:array<string,string>}|null
     */
    private function linearMatch(string $method, array $pathSegments): ?array
    {
        $routes = $this->dynamicRoutes[$method] ?? [];
        foreach ($routes as $route) {
            /** @var array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $route */
            if (count($route['segments']) !== count($pathSegments)) {
                continue;
            }
            $params = [];
            $match = true;
            foreach ($route['segments'] as $i => $segment) {
                if ($this->isParamSegment($segment)) {
                    $paramName = substr($segment, 1, -1);
                    if ($paramName === '') { $match = false; break; }
                    $params[$paramName] = $pathSegments[$i];
                } elseif ($segment !== $pathSegments[$i]) {
                    $match = false;
                    break;
                }
            }
            if (!$match) { continue; }

            $constraints = $this->getWhereConstraints($method, $route['path']);
            if ($constraints !== []) {
                foreach ($params as $pn => $pv) {
                    if (isset($constraints[$pn]) && !preg_match($constraints[$pn], (string) $pv)) {
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

    public function pathExists(string $path): bool
    {
        foreach ($this->staticRoutes as $routes) {
            if (isset($routes[$path])) {
                return true;
            }
        }
        $segments = $this->splitSegments($path);
        foreach ($this->dynamicRoutes as $method => $routeList) {
            foreach ($routeList as $route) {
                /** @var array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $route */
                if (count($route['segments']) !== count($segments)) { continue; }
                $match = true;
                foreach ($route['segments'] as $i => $segment) {
                    if (!$this->isParamSegment($segment) && $segment !== $segments[$i]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) { return true; }
            }
        }
        return false;
    }

    /**
     * @return array<int, array{method:string,path:string,handler:string,middleware:string,cache_ttl:int,where?:array<string,string>}>
     */
    public function getRoutes(): array
    {
        if ($this->routeListCache !== null) {
            return $this->routeListCache;
        }

        $routes = [];

        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $route) {
                /** @var array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $route */
                $where = $this->getWhereConstraints($method, $path);
                $entry = [
                    'method' => $method,
                    'path' => $path,
                    'handler' => self::handlerToString($route['handler']),
                    'middleware' => implode(', ', array_map(fn (mixed $m): string => self::middlewareToString($m), $route['middleware'])),
                    'cache_ttl' => $route['cache_ttl'],
                ];
                if ($where !== []) {
                    $entry['where'] = $where;
                }
                $routes[] = $entry;
            }
        }

        foreach ($this->dynamicRoutes as $method => $routeList) {
            foreach ($routeList as $route) {
                /** @var array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $route */
                $where = $this->getWhereConstraints($method, $route['path']);
                $entry = [
                    'method' => $method,
                    'path' => $route['path'],
                    'handler' => self::handlerToString($route['handler']),
                    'middleware' => implode(', ', array_map(fn (mixed $m): string => self::middlewareToString($m), $route['middleware'])),
                    'cache_ttl' => $route['cache_ttl'],
                ];
                if ($where !== []) {
                    $entry['where'] = $where;
                }
                $routes[] = $entry;
            }
        }

        usort($routes, function (array $a, array $b): int {
            $cmp = $a['path'] <=> $b['path'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['method'] <=> $b['method'];
        });

        $this->routeListCache = $routes;
        return $routes;
    }

    /**
     * @return array{static:array<string,array<string,array{path:string,handler:string,handler_raw:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>,dynamic:array<string,array<int,array{path:string,segments:array<int,string>,handler:string,handler_raw:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int}>>}
     */
    public function export(): array
    {
        if (isset($this->exportCache['static'], $this->exportCache['dynamic'])) {
            return $this->exportCache;
        }

        $static = [];
        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $route) {
                /** @var array{path:string,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $route */
                $static[$method][$path] = [
                    'path' => $route['path'],
                    'handler' => self::handlerToString($route['handler']),
                    'handler_raw' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        $dynamic = [];
        foreach ($this->dynamicRoutes as $method => $routeList) {
            foreach ($routeList as $route) {
                /** @var array{path:string,segments:array<int,string>,handler:callable|array{0:class-string,1:string}|string,middleware:array<int,callable|string>,cache_ttl:int} $route */
                $dynamic[$method][] = [
                    'path' => $route['path'],
                    'segments' => $route['segments'],
                    'handler' => self::handlerToString($route['handler']),
                    'handler_raw' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        $this->exportCache = ['static' => $static, 'dynamic' => $dynamic];
        return $this->exportCache;
    }

    /**
     * @param callable|array{0:class-string,1:string}|string $handler
     */
    public static function handlerToString(callable|array|string $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }
        if (is_array($handler)) {
            /** @var array{0:class-string,1:string} $handler */
            return $handler[0] . '@' . $handler[1];
        }
        return 'Closure';
    }

    public static function middlewareToString(callable|string $middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        }
        return 'callable';
    }

    /**
     * @return array<string, string>
     */
    private function getWhereConstraints(string $method, string $path): array
    {
        $key = strtoupper($method) . ':' . self::normalizePath($path);
        return $this->whereConstraints[$key] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function splitSegments(string $path): array
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    private function isParamSegment(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }

    public static function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '/.' ? '/' : $normalized;
    }
}
