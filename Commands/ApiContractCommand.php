<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

/** Verifies that generated OpenAPI operations still exist in the router.
 * --strict: warnings count as failures. --coverage: also fail when a
 * registered route has no operation in the spec (reverse direction). */
final class ApiContractCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    /**
     * @var list<string> Route files that failed to load (with the
     * underlying error). A contract check against routes that could not
     * be loaded must FAIL, never silently skip the file.
     */
    private array $skippedRouteFiles = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $specFile = $this->basePath . '/docs/openapi/openapi.json';
        $strict = in_array('--strict', $args, true);
        $coverage = in_array('--coverage', $args, true);
        $pathFilter = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--spec=')) {
                $specFile = (string) substr($arg, 7);
            }
            if (str_starts_with($arg, '--path=')) {
                $pathFilter = (string) substr($arg, 7);
            }
        }
        if (!is_file($specFile)) {
            $this->error('OpenAPI spec not found: ' . $specFile);
            return 1;
        }
        $spec = json_decode((string) file_get_contents($specFile), true);
        if (!is_array($spec) || !is_array($spec['paths'] ?? null)) {
            $this->error('Invalid OpenAPI document or missing paths.');
            return 1;
        }

        $routes = $this->routes();
        $passed = 0;
        $warnings = 0;
        $failed = 0;
        $checked = 0;        foreach ($spec['paths'] as $path => $operations) {
            if ($pathFilter !== null && !str_contains((string) $path, $pathFilter)) {
                continue;
            }
            if (!is_array($operations)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                if (!is_string($method) || !in_array(strtoupper($method), ['GET','POST','PUT','PATCH','DELETE','OPTIONS'], true)) {
                    continue;
                }
                $checked++;
                $found = false;
                foreach ($routes as $route) {
                    $routeMethod = is_string($route['method'] ?? null) ? $route['method'] : '';
                    $routePath = is_string($route['path'] ?? null) ? $route['path'] : '';
                    if (
                        strtoupper($routeMethod) === strtoupper($method)
                        && $this->matchesRoute((string) $path, $routePath, $routes, (string) $method)
                    ) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $failed++;
                    $this->error('Missing route: ' . strtoupper((string) $method) . ' ' . $path);
                    continue;
                }
                $responses = is_array($operation) ? ($operation['responses'] ?? []) : [];
                if (!is_array($responses) || $responses === []) {
                    $warnings++;
                    $this->warn('No declared responses: ' . strtoupper((string) $method) . ' ' . $path);
                } else {
                    $passed++;
                }
            }
        }
        if ($coverage) {
            // Reverse direction: every registered route must have a spec
            // operation. A spec that omits routes passes --strict silently
            // (it only checks spec→routes); --coverage closes that gap.
            // OPTIONS routes are preflight, not API operations — skipped.
            // $spec['paths'] is proven an array by the guard at the top of
            // run() — no is_array() re-check needed here.
            $specPaths = $spec['paths'];
            foreach ($routes as $route) {
                $routeMethod = strtoupper(is_string($route['method'] ?? null) ? $route['method'] : '');
                $routePath = is_string($route['path'] ?? null) ? $route['path'] : '';
                if ($routeMethod === '' || $routePath === '' || $routeMethod === 'OPTIONS') {
                    continue;
                }
                if (!$this->specHasOperation($specPaths, $routeMethod, $routePath, $routes)) {
                    $failed++;
                    $this->error('Route not covered by spec: ' . $routeMethod . ' ' . $routePath);
                }
            }
        }
        if ($checked === 0) {
            // A filter that matches nothing (or an empty spec) must not
            // report a silent pass: exit non-zero and say so plainly.
            if ($pathFilter !== null) {
                $this->warn("No operations matched --path filter '{$pathFilter}'. Nothing was checked.");
            } else {
                $this->warn('No operations to check in spec. Nothing was checked.');
            }
        }
        if ($this->skippedRouteFiles !== []) {
            $this->error('Could not load route file(s): ' . implode('; ', $this->skippedRouteFiles));
        }
        $this->write("Contract: {$passed} passed, {$warnings} warnings, {$failed} failed");
        return $failed > 0 || ($strict && $warnings > 0) || $checked === 0 || $this->skippedRouteFiles !== [] ? 1 : 0;
    }

    /**
     * @param array<mixed, mixed> $specPaths Decoded spec paths (keys are
     *     strings in a valid document; cast inside).
     * @param list<array<string, mixed>> $routes Registered routes (for
     *     the placeholder literal guard in matchesRoute()).
     */
    private function specHasOperation(array $specPaths, string $method, string $path, array $routes): bool
    {
        foreach ($specPaths as $specPath => $operations) {
            if (!is_array($operations)) {
                continue;
            }
            foreach ($operations as $opMethod => $operation) {
                if (!is_string($opMethod) || strtoupper($opMethod) !== $method) {
                    continue;
                }
                if ($this->matchesRoute((string) $specPath, $path, $routes, $method)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Does a spec path match a registered route path?
     *
     * Chosen approach — literal-first with a placeholder guard: a
     * placeholder segment ({id}) never matches a segment that exists as
     * a LITERAL segment of a route with the same method and the same
     * static parts. E.g. spec GET /api/auth/{id} must not "pass" by
     * matching GET /api/auth/me (the smoke false-pass). A real
     * parameterized route (GET /api/auth/{id} in the router) still
     * matches, because its segment is itself a placeholder, not a
     * literal — and a spec placeholder matches it via the regex below.
     *
     * @param list<array<string, mixed>> $routes Registered routes.
     */
    private function matchesRoute(string $spec, string $actual, array $routes, string $method): bool
    {
        if (!$this->samePath($spec, $actual)) {
            return false;
        }
        if (!str_contains($spec, '{')) {
            return true;
        }
        $specSegments = explode('/', trim($spec, '/'));
        $actualSegments = explode('/', trim($actual, '/'));
        foreach ($specSegments as $i => $segment) {
            if (!str_starts_with($segment, '{')) {
                continue;
            }
            $candidate = $actualSegments[$i] ?? '';
            if ($candidate === '' || str_starts_with($candidate, '{')) {
                continue;
            }
            // The candidate segment is a literal. Block the match when
            // any route with the same method and the same static
            // segments owns this literal at this position — including
            // the candidate route itself (that is the false-pass case).
            foreach ($routes as $route) {
                $routeMethod = is_string($route['method'] ?? null) ? (string) $route['method'] : '';
                if (strtoupper($routeMethod) !== strtoupper($method)) {
                    continue;
                }
                $routePath = is_string($route['path'] ?? null) ? (string) $route['path'] : '';
                $routeSegments = explode('/', trim($routePath, '/'));
                $staticMatch = true;
                foreach ($specSegments as $j => $static) {
                    if (str_starts_with($static, '{')) {
                        continue;
                    }
                    if (($routeSegments[$j] ?? null) !== $static) {
                        $staticMatch = false;
                        break;
                    }
                }
                if (!$staticMatch) {
                    continue;
                }
                if (($routeSegments[$i] ?? null) === $candidate) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    private function routes(): array
    {
        $app = new App($this->basePath);
        $app->boot();
        foreach (glob($this->basePath . '/routes/*.php') ?: [] as $file) {
            try {
                $app->loadRoutes($file);
            } catch (\Throwable $e) {
                // The original S-07 trigger (routes/schedule.php needing
                // $schedule) is fixed in App::loadRoutes(), so any file
                // that still throws here is genuinely broken. Record it
                // and FAIL the check below — silently skipping it could
                // false-pass a contract against routes never loaded.
                // (Kept as catch + explicit failure, not a rethrow:
                // Console has no exception handler, so a raw throw
                // would surface as an uncaught fatal with a stack
                // trace instead of a clean error naming the file.)
                $this->skippedRouteFiles[] = basename($file) . ': ' . $e->getMessage();
            }
        }
        $router = $app->router();
        /** @var list<array<string, mixed>> $routes */
        $routes = array_values($router->getRoutes());
        return $routes;
    }

    private function samePath(string $spec, string $actual): bool
    {
        $pattern = preg_replace('/\{[^}]+\}/', '[^/]+', rtrim($spec, '/'));
        return is_string($pattern) && (bool) preg_match('#^' . $pattern . '/?$#', rtrim($actual, '/'));
    }
}
