<?php

declare(strict_types=1);

namespace Siro\Core;

final class Route
{
    public function __construct(
        private readonly Router $router,
        private readonly string $method,
        private readonly string $path
    ) {
    }

    /**
     * @param array<int, callable|string>|callable|string $middleware
     */
    public function middleware(array|callable|string $middleware): self
    {
        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        $this->router->setRouteMiddleware($this->method, $this->path, $middleware);
        return $this;
    }

    public function cache(int $ttl = 60): self
    {
        $this->router->setRouteCacheTTL($this->method, $this->path, $ttl);
        return $this;
    }
}
