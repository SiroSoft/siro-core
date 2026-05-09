<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class ConfigClearCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $_args): int
    {
        $this->write('Clearing cache...');

        // Clear config cache
        $configCache = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($configCache)) {
            @unlink($configCache);
            $this->write('  Config cache cleared.');
        }

        // Clear routes cache
        $routesCache = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_file($routesCache)) {
            @unlink($routesCache);
            $this->write('  Routes cache cleared.');
        }

        // Clear config repository cache
        \Siro\Core\Config::clearCache();

        // Clear env cache
        \Siro\Core\Env::clearCache($this->basePath);

        $this->write('Cache cleared successfully!');
        return 0;
    }
}