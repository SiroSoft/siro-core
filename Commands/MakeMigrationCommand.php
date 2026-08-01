<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate a database migration file.
 *
 * Creates a timestamped migration file with an anonymous class
 * skeleton containing up() and down() methods.
 *
 * @package Siro\Core\Commands
 */
final class MakeMigrationCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Migration name is required. Example: php siro make:migration create_users_table');
            return 1;
        }

        $normalized = $this->normalizeName($name);
        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_' . $normalized . '.php';

        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, $this->template($normalized));
        $this->write('Generated: database/migrations/' . $filename);

        return 0;
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? 'migration';
        $name = trim($name, '_');

        return $name !== '' ? $name : 'migration';
    }

    private function template(string $name): string
    {
        $table = $this->deriveTableName($name);

        return <<<PHP
<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class {
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$t) {
            \$t->id();
            \$t->string('name');
            \$t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('{$table}');
    }
};

PHP;
    }

    /**
     * Derive a table name from the migration name using common Laravel-style
     * conventions (create_users_table -> users, add_role_to_users -> users).
     */
    private function deriveTableName(string $name): string
    {
        $lower = strtolower($name);

        if (preg_match('/^create_(\w+)_table$/', $lower, $m)) {
            return $m[1];
        }
        if (preg_match('/^create_(\w+)$/', $lower, $m)) {
            return $m[1];
        }
        if (preg_match('/^(?:add|drop|remove|change|update|rename)_\w+_to_(\w+)_table$/', $lower, $m)) {
            return $m[1];
        }
        if (preg_match('/^(?:add|drop|remove|change|update|rename)_\w+_to_(\w+)$/', $lower, $m)) {
            return $m[1];
        }
        if (preg_match('/^add_\w+_to_(\w+)_table$/', $lower, $m)) {
            return $m[1];
        }

        return 'table_name';
    }
}
