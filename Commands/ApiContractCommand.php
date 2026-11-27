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
        foreach ($spec['paths'] as $path => $operations) {
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
                $found = false;
                foreach ($routes as $route) {
                    $routeMethod = is_string($route['method'] ?? null) ? $route['method'] : '';
                    $routePath = is_string($route['path'] ?? null) ? $route['path'] : '';
                    if (
                        strtoupper($routeMethod) === strtoupper($method)
                        && $this->samePath((string) $path, $routePath)
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
                if (!$this->specHasOperation($specPaths, $routeMethod, $routePath)) {
                    $failed++;
                    $this->error('Route not covered by spec: ' . $routeMethod . ' ' . $routePath);
                }
            }
        }
        $this->write("Contract: {$passed} passed, {$warnings} warnings, {$failed} failed");
        return $failed > 0 || ($strict && $warnings > 0) ? 1 : 0;
    }

    /**
     * @param array<mixed, mixed> $specPaths Decoded spec paths (keys are
     *     strings in a valid document; cast inside).
     */
    private function specHasOperation(array $specPaths, string $method, string $path): bool
    {
        foreach ($specPaths as $specPath => $operations) {
            if (!is_array($operations)) {
                continue;
            }
            foreach ($operations as $opMethod => $operation) {
                if (!is_string($opMethod) || strtoupper($opMethod) !== $method) {
                    continue;
                }
                if ($this->samePath((string) $specPath, $path)) {
                    return true;
                }
            }
        }
        return false;
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
                // Schedule/console route files can require a scheduler object
                // that is not part of HTTP bootstrap. Keep contract checks
                // useful for API routes and report the skipped file.
                $this->warn('Skipped non-HTTP route file: ' . basename($file));
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
