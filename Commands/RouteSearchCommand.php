<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class RouteSearchCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $keyword = trim((string) ($args[0] ?? ''));

        if ($keyword === '') {
            $this->write('Usage: php siro route:search <keyword>');
            $this->write('');
            $this->write('Examples:');
            $this->write('  php siro route:search user');
            $this->write('  php siro route:search /api/products');
            return 1;
        }

        $app = new \Siro\Core\App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');

        $routes = $app->router->getRoutes();
        $matched = [];

        foreach ($routes as $route) {
            $haystack = ($route['method'] ?? '') . ' ' . ($route['path'] ?? '') . ' ' . ($route['handler'] ?? '');
            if (str_contains(strtolower($haystack), strtolower($keyword))) {
                $matched[] = $route;
            }
        }

        if ($matched === []) {
            $this->write('No routes match "' . $keyword . '".');
            $this->write('');
            $this->write('All routes: php siro route:list');
            return 0;
        }

        $this->write('Found ' . count($matched) . ' route(s) matching "' . $keyword . '":');
        $this->write('');

        $this->table(
            ['Method', 'Path', 'Handler', 'Middleware'],
            array_map(fn ($r) => [
                $r['method'] ?? '?',
                $r['path'] ?? '?',
                $r['handler'] ?? '?',
                ($r['middleware'] ?? '') . (($r['cache_ttl'] ?? 0) > 0 ? ' [cache:' . $r['cache_ttl'] . 's]' : ''),
            ], $matched)
        );

        return 0;
    }
}
