<?php

declare(strict_types=1);

namespace Siro\Core;

interface ExceptionHandlerInterface
{
    public static function handle(\Throwable $e, Request $request): Response;
}
