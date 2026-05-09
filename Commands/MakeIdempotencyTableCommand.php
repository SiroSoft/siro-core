<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Commands\CommandSupport;

/**
 * Create idempotency table migration.
 */
final class MakeIdempotencyTableCommand
{
    use CommandSupport;

    public function run(array $_args): int
    {
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

    public function help(): void
    {
        echo "\nUsage: php siro make:idempotency-table\n\n";
        echo "Creates the idempotency_keys table for IdempotencyMiddleware.\n";
        echo "This table stores responses for duplicate requests within a TTL window.\n\n";
    }
}