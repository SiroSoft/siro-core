<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

final class RouteListCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
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
            $meta = $route['middleware'] !== '' ? $route['middleware'] : '-';
            if ($route['cache_ttl'] > 0) {
                $meta .= ' [cache:' . $route['cache_ttl'] . 's]';
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
