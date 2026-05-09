<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed;
}
