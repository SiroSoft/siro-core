<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * HTTP method enum for type-safe route registration.
 *
 * Usage:
 *   $router->add(Method::GET->value, '/path', $handler);
 *
 * @package Siro\Core
 */
enum Method: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
    case HEAD = 'HEAD';
}
