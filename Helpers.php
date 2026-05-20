<?php

declare(strict_types=1);

namespace Siro\Core;

if (!function_exists('Siro\Core\sd')) {
    /**
     * Siro Dump — dump variables and stop execution.
     * Alias: dd() also available for Laravel refugees.
     */
    function sd(mixed ...$vars): never
    {
        foreach ($vars as $v) {
            var_dump($v);
        }
        exit(1);
    }
}

// dd() kept as alias for developer convenience
if (!function_exists('Siro\Core\dd')) {
    function dd(mixed ...$vars): never
    {
        sd(...$vars);
    }
}

if (!function_exists('Siro\Core\dump')) {
    function dump(mixed $var): mixed
    {
        var_dump($var);
        return $var;
    }
}