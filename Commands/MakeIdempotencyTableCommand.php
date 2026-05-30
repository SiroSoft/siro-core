<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Commands\CommandSupport;

/**
 * Create idempotency table migration.
 */
final class MakeIdempotencyTableCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $_args */
    public function run(array $_args): int
    {
        $this->initDatabase();
        $this->info('Creating idempotency_keys table...');

        try {
            \Siro\Core\Auth\Idempotency::createTable();
            $this->info('✓ Idempotency table created successfully.');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to create idempotency table: ' . $e->getMessage());
            return 1;
        }
    }

    private function initDatabase(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($configPath)) {
            $config = require $configPath;
            if (is_array($config)) {
                \Siro\Core\Database::configure($config);
            }
        }
    }

    public function help(): void
    {
        echo "\nUsage: php siro make:idempotency-table\n\n";
        echo "Creates the idempotency_keys table for IdempotencyMiddleware.\n";
        echo "This table stores responses for duplicate requests within a TTL window.\n\n";
    }
}