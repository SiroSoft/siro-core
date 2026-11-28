<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

/**
 * Cache routes for faster boot.
 *
 * Compiles routes/api.php into storage/framework/routes.php
 * (HMAC-signed, same format App::loadRoutes() reads). Closure
 * handlers are skipped by the exporter and can never be cached.
 * Clear with `php siro config:clear`.
 *
 * @package Siro\Core\Commands
 */
final class RouteCacheCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $routesFile = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
        if (!is_file($routesFile)) {
            $this->write('No routes file found: routes/api.php');
            return 1;
        }

        $cacheFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'routes.php';

        // Drop any stale cache first: App::loadRoutes() prefers the cache
        // over routes/api.php, so a rebuild must start from source.
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($routesFile);

        $routes = $app->router->getRoutes();
        if ($routes === []) {
            $this->write('No routes defined — nothing to cache.');
            return 0;
        }

        if (!$app->router->saveToCache($cacheFile)) {
            $this->write('  Error: could not write routes cache to ' . $cacheFile);
            $this->write('  Check that storage/framework is writable.');
            return 1;
        }

        $this->write('Routes cached successfully! (' . count($routes) . ' routes)');
        $this->write('  Cache: storage/framework/routes.php');
        $this->write('  Clear with: php siro config:clear');

        return 0;
    }
}
