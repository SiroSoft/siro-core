<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

final class ConfigCacheCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Cache environment configuration.
 *
 * Compiles .env and config/database.php into a single cached
 * PHP file for faster boot times in production.
 *
 * @package Siro\Core\Commands
 */
    public function run(array $args): int
    {
        $cacheDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        // Collect env vars
        $env = [];
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    $value = trim($value, '"\'');
                    $env[$key] = $value;
                }
            }
        }

        // Collect db config
        $dbConfig = [];
        $dbConfigPath = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($dbConfigPath)) {
            $dbConfig = require $dbConfigPath;
        }

        $content = '<?php return ' . var_export([
            'env' => $env,
            'db' => $dbConfig,
            'cached_at' => date('Y-m-d H:i:s'),
        ], true) . ';' . PHP_EOL;

        file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'config.php', $content);
        $this->write('Config cached successfully!');

        return 0;
    }
}
