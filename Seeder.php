<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use Siro\Core\DB\QueryBuilder;

/**
 * Base class for database seeders.
 *
 * Extend this class instead of writing ad-hoc seeder classes:
 *
 *   final class UserSeeder extends Seeder
 *   {
 *       public function run(): void
 *       {
 *           $this->insertIgnore('users', ['email' => 'admin@example.com']);
 *       }
 *   }
 *
 * Seeders are discovered by SeedCommand: place them under the
 * configured seed directory (SEED_PATH, default database/seeds/)
 * and run them with `php siro db:seed`, `php siro db:seed UserSeeder`,
 * or through a DatabaseSeeder with ordered $calls.
 *
 * @package Siro\Core
 */
abstract class Seeder
{
    abstract public function run(): void;

    /**
     * Run another seeder, by class name or instance.
     */
    public function call(string|Seeder $seeder): void
    {
        $instance = is_string($seeder) ? new $seeder() : $seeder;
        if (!method_exists($instance, 'run')) {
            throw new \RuntimeException('Seeder must have a run() method: ' . (is_string($seeder) ? $seeder :
                $instance::class));
        }
        /** @var object{run(): void} $instance */
        $instance->run();
    }

    /**
     * Insert a row, silently skipping it when it already exists.
     *
     * Only duplicate-key errors are swallowed: MySQL 1062, Postgres
     * 23505, SQLite UNIQUE constraint failures. Foreign-key errors
     * (MySQL 1451/1452, Postgres 23503, SQLite FOREIGN KEY failures)
     * and every other error are re-thrown — a seeder must never hide
     * broken referential data.
     *
     * Returns true when the row was inserted, false when it was
     * skipped as a duplicate.
     *
     * @param array<string, mixed> $data
     */
    protected function insertIgnore(string $table, array $data, ?string $connection = null): bool
    {
        try {
            $this->table($table, $connection)->insert($data);
            return true;
        } catch (\PDOException $e) {
            if ($this->isDuplicateKeyError($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Insert a row and return its id.
     *
     * @param array<string, mixed> $data
     */
    protected function insert(string $table, array $data, ?string $connection = null): int
    {
        return $this->table($table, $connection)->insert($data);
    }

    protected function db(?string $connection = null): PDO
    {
        return Database::connection($connection);
    }

    protected function table(string $table, ?string $connection = null): QueryBuilder
    {
        return Database::table($table, $connection);
    }

    /**
     * True only for duplicate-key errors, never for foreign-key errors.
     */
    private function isDuplicateKeyError(\PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $errorInfo = $e->errorInfo;
        $driverCode = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;
        $message = $e->getMessage();

        // MySQL duplicate key: SQLSTATE 23000 + driver code 1062.
        // (FK errors 1451/1452 share SQLSTATE 23000 but have a
        // different driver code, so they are re-thrown.)
        if ($sqlState === '23000' && $driverCode === 1062) {
            return true;
        }

        // Postgres unique violation: SQLSTATE 23505.
        if ($sqlState === '23505') {
            return true;
        }

        // SQLite unique constraint: SQLSTATE 23000 + driver code 19
        // + a UNIQUE message. (SQLite FK failures carry the message
        // "FOREIGN KEY constraint failed" instead.)
        if ($sqlState === '23000' && $driverCode === 19 && str_contains($message, 'UNIQUE constraint failed')) {
            return true;
        }

        return false;
    }
}
