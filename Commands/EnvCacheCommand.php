<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

final class EnvCacheCommand implements \Siro\Core\Commands\CommandInterface
{
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

        // Env cache integrity requires APP_KEY (min 16 chars): once a cache
        // file exists, Env::load() throws on EVERY boot when the .env has
        // no valid APP_KEY — bricking the project so badly that even
        // config:clear cannot run (only deleting storage/framework/env.php
        // by hand recovers it). Refuse to write the cache instead.
        // The key must live in .env itself: that is the file Env::load()
        // reads it from when validating the cache.
        if (strlen(self::readAppKey($envFile)) < 16) {
            $this->write('  ✗ Refusing to cache env: APP_KEY is missing or shorter than 16 chars.');
            $this->write('  Env cache integrity requires APP_KEY (min 16 chars) in .env.');
            $this->write('  Generate one with: php -r "echo bin2hex(random_bytes(16));"');
            $this->write('  then add APP_KEY=<output> to .env (32 chars recommended).');
            $this->write('  No cache file was written; the project is unchanged.');
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
