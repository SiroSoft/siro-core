<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class OptimizeCommand implements \Siro\Core\Commands\CommandInterface
{
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
        // Env cache integrity requires APP_KEY (min 16 chars) in .env:
        // caching env without it bricks the project on the next boot
        // (Env::load() throws for every command, even config:clear).
        // Refuse up front, before writing any cache file.
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile) && strlen(self::readAppKey($envFile)) < 16) {
            $this->write('Optimizing SiroPHP...');
            $this->write('');
            $this->write('  ✗ Refusing to optimize: APP_KEY is missing or shorter than 16 chars.');
            $this->write('  Env cache integrity requires APP_KEY (min 16 chars) in .env.');
            $this->write('  Generate one with: php -r "echo bin2hex(random_bytes(16));"');
            $this->write('  then add APP_KEY=<output> to .env (32 chars recommended).');
            $this->write('  No cache file was written; the project is unchanged.');
            return 1;
        }

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

    /**
     * Read APP_KEY from the .env file itself (raw parse, same source
     * Env::load() uses for its cache-integrity check). Returns '' when
     * the key is absent. Surrounding quotes are stripped like
     * Env::parseEnvFile() does.
     */
    private static function readAppKey(string $envFile): string
    {
        $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return '';
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            if (trim(substr($line, 0, $pos)) !== 'APP_KEY') {
                continue;
            }
            $value = trim(substr($line, $pos + 1));
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            return $value;
        }
        return '';
    }
}
