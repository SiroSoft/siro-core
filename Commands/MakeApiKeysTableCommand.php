<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Commands\CommandSupport;

/**
 * Create API keys table migration.
 */
final class MakeApiKeysTableCommand
{
    use CommandSupport;

    public function run(array $args): int
    {
        $this->info('Creating api_keys table...');

        try {
            \Siro\Core\Auth\ApiKey::createTable();
            $this->info('✓ API keys table created successfully.');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to create api_keys table: ' . $e->getMessage());
            return 1;
        }
    }

    public function help(): void
    {
        echo "\nUsage: php siro make:apikey-table\n\n";
        echo "Creates the api_keys table for ApiKeyMiddleware.\n";
        echo "This table stores API keys for external developer authentication.\n\n";
    }
}
