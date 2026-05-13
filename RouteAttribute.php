<?php

declare(strict_types=1);

namespace Siro\Core;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RouteAttribute
{
    /** @var array<int, string> */
    public readonly array $methods;

    /**
     * @param string $path URL path e.g. "/api/users"
     * @param string|array<int, string> $method HTTP method(s): "GET", ["GET", "POST"]
     * @param array<int, string> $middleware Middleware aliases
     * @param int $cacheTtl Response cache TTL in seconds
     */
    public function __construct(
        public readonly string $path,
        string|array $method = 'GET',
        public readonly array $middleware = [],
        public readonly int $cacheTtl = 0,
    ) {
        $this->methods = is_array($method) ? array_map('strtoupper', $method) : [strtoupper($method)];
    }
}
