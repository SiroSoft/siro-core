<?php

declare(strict_types=1);

namespace Siro\Core;

final class RouteMatcher
{
    /** @var array<string, array<string, array>> */
    private array $staticRoutes;
    /** @var array<string, array<int, array>> */
    private array $dynamicRoutes;
    /** @var array<string, array<string, string>> */
    private array $whereConstraints;

    /** @var array<string, array{path:string,handler:mixed,middleware:array,cache_ttl:int,params:array}|null> */
    private array $matchCache = [];

    /** @var array<string, array{static:array,dynamic:array}> */
    private array $exportCache = [];
    /** @var array<int, array>|null */
    private ?array $routeListCache = null;

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

    private function linearMatch(string $method, array $pathSegments): ?array
    {
        $routes = $this->dynamicRoutes[$method] ?? [];
        foreach ($routes as $route) {
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

    public function getRoutes(): array
    {
        if ($this->routeListCache !== null) {
            return $this->routeListCache;
        }

        $routes = [];

        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $route) {
                $routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'handler' => self::handlerToString($route['handler']),
                    'middleware' => implode(', ', array_map(fn (mixed $m): string => self::middlewareToString($m), $route['middleware'])),
                    'cache_ttl' => $route['cache_ttl'],
                ];
            }
        }

        foreach ($this->dynamicRoutes as $method => $routeList) {
            foreach ($routeList as $route) {
                $routes[] = [
                    'method' => $method,
                    'path' => $route['path'],
                    'handler' => self::handlerToString($route['handler']),
                    'middleware' => implode(', ', array_map(fn (mixed $m): string => self::middlewareToString($m), $route['middleware'])),
                    'cache_ttl' => $route['cache_ttl'],
                ];
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

    public function export(): array
    {
        if (isset($this->exportCache['static'], $this->exportCache['dynamic'])) {
            return $this->exportCache;
        }

        $static = [];
        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $route) {
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

    public static function handlerToString(callable|array|string $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }
        if (is_array($handler)) {
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

    private function getWhereConstraints(string $method, string $path): array
    {
        $key = strtoupper($method) . ':' . self::normalizePath($path);
        return $this->whereConstraints[$key] ?? [];
    }

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
