<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Commands\CommandSupport;

final class MakeApiKeysTableCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->initDatabase();
        $this->info('Creating api_keys table...');

        try {
            \Siro\Core\Auth\ApiKey::createTable();
            $this->info('API keys table created successfully.');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to create api_keys table: ' . $e->getMessage());
            return 1;
        }
    }

    private function initDatabase(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($configPath)) {
            /** @var array<string, mixed> $config */
            $config = require $configPath;
            \Siro\Core\Database::configure($config);
        }
    }

    public function help(): void
    {
        echo "\nUsage: php siro make:apikey-table\n\n";
        echo "Creates the api_keys table for ApiKeyMiddleware.\n";
        echo "This table stores API keys for external developer authentication.\n\n";
    }
}
