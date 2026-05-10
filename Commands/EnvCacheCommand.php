<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

final class EnvCacheCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envFile)) {
            $this->write('  ⚠ .env file not found.');
            return 1;
        }

        Env::load($envFile);

        if (Env::cache($envFile)) {
            $this->write('  ✓ Env cached to storage/framework/env.php');
            return 0;
        }

        $this->write('  ⚠ Failed to cache env.');
        return 1;
    }
}