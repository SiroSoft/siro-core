<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * HTTP method constants for type-safe route registration.
 *
 * Usage:
 *   $router->add(Method::GET, '/path', $handler);
 *
 * @package Siro\Core
 */
final class Method
{
    public const GET = 'GET';
    public const POST = 'POST';
    public const PUT = 'PUT';
    public const PATCH = 'PATCH';
    public const DELETE = 'DELETE';
    public const OPTIONS = 'OPTIONS';
    public const HEAD = 'HEAD';
}
