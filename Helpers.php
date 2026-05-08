<?php

declare(strict_types=1);

namespace Siro\Core;

if (!function_exists('Siro\Core\dd')) {
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $v) {
            var_dump($v);
        }
        exit(1);
    }
}

if (!function_exists('Siro\Core\dump')) {
    function dump(mixed $var): mixed
    {
        var_dump($var);
        return $var;
    }
}