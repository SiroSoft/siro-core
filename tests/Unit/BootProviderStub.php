<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\App;

/**
 * Minimal package provider stub for provider-discovery tests. Only the
 * static flag is asserted on; register() must be called by App::boot()
 * when the stub is declared in installed.json.
 */

final class BootProviderStub
{
    public static bool $registered = false;

    public function register(App $app): void
    {
        self::$registered = true;
    }
}
