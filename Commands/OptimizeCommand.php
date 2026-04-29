<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class OptimizeCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->write('Optimizing SiroPHP...');
        $this->write('');

        // Cache config
        $this->write('  Caching config...');
        $configCmd = new ConfigCacheCommand($this->basePath);
        $configCmd->run([]);

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
}
