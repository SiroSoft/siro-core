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

        $methodPad = 8;
        $pathPad = 50;

        $this->write(str_pad('Method', $methodPad) . ' ' . str_pad('Path', $pathPad) . ' Middleware / Cache');
        $this->write(str_repeat('-', $methodPad + $pathPad + 40));

        foreach ($routes as $route) {
            $method = str_pad($route['method'], $methodPad);
            $path = str_pad($route['path'], $pathPad);

            $meta = $route['middleware'] !== '' ? $route['middleware'] : '-';
            if ($route['cache_ttl'] > 0) {
                $meta .= ' [cache:' . $route['cache_ttl'] . 's]';
            }

            $this->write($method . ' ' . $path . ' ' . $meta);
        }

        return 0;
    }
}
