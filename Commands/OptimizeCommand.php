<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class OptimizeCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Optimize the application for production.
 *
 * Runs config:cache and composer dump-autoload to improve
 * boot time and autoloading performance.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $this->write('Optimizing SiroPHP...');
        $this->write('');

        // Cache env
        $this->write('  Caching env...');
        \Siro\Core\Env::cache($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $this->write('  Env cached.');

        // Cache config
        $this->write('  Caching config...');
        $configCmd = new ConfigCacheCommand($this->basePath);
        $configCmd->run([]);

        // Cache routes
        $this->write('  Caching routes...');
        $this->cacheRoutes();

        // Dump autoloader
        $this->write('  Optimizing autoloader...');
        $autoloadPath = $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoloadPath)) {
            exec('composer dump-autoload --no-dev --quiet -d ' . escapeshellarg($this->basePath) . ' 2>&1', $output, $code);
            if ($code === 0) {
                $this->write('  Autoloader optimized.');
            } else {
                $this->write('  Autoloader optimize failed (try running composer dump-autoload manually).');
            }
        }

        $this->write('');
        $this->write('Optimization complete!');
        return 0;
    }

    private function cacheRoutes(): void
    {
        $cacheFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'routes.php';

        $app = new \Siro\Core\App($this->basePath);
        $app->router()->saveToCache($cacheFile);
        $this->write('  Routes cached to: storage/framework/routes.php');
    }
}
