<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

final class RouteListCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Display all registered routes.
 *
 * Lists every route with its method, path, handler, and
 * middleware in a formatted table.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');

        $routes = $app->router->getRoutes();

        if ($routes === []) {
            $this->write('No routes defined.');
            return 0;
        }

        $rows = [];
        foreach ($routes as $route) {
            $middleware = $route['middleware'];
            $cacheTtl = $route['cache_ttl'];
            $meta = $middleware !== '' ? $middleware : '-';
            if ($cacheTtl > 0) {
                $meta .= ' [cache:' . $cacheTtl . 's]';
            }

            $rows[] = [$route['method'], $route['path'], $route['handler'], $meta];
        }

        $this->table(
            ['Method', 'Path', 'Handler', 'Middleware'],
            $rows
        );

        return 0;
    }
}
