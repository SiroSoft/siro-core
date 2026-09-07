<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        \Siro\Core\Response::setRequestMeta('', 0);
        parent::tearDown();
    }

    protected function createTempFile(string $content = ''): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'siro_test_');
        file_put_contents($tempFile, $content);
        return $tempFile;
    }

    protected function removeFile(string $file): void
    {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /** @param array<string, mixed> $data */
    protected function assertJsonStructure(array $structure, array $data, ?string $parentKey = null): void
    {
        foreach ($structure as $key => $expectedType) {
            $fullKey = $parentKey !== null ? $parentKey . '.' . $key : $key;
            $this->assertArrayHasKey($key, $data, "Missing key: {$fullKey}");

            if (is_array($expectedType)) {
                $this->assertIsArray($data[$key], "Expected array at: {$fullKey}");
                $this->assertJsonStructure($expectedType, $data[$key], $fullKey);
            } else {
                $assertion = 'assertIs' . ucfirst($expectedType);
                if (method_exists($this, $assertion)) {
                    $this->{$assertion}($data[$key], "Expected {$expectedType} at: {$fullKey}");
                }
            }
        }
    }

    protected function actingAs(string $modelClass, int $userId): void
    {
        $user = $modelClass::find($userId);
        if ($user !== null) {
            $token = \Siro\Core\Auth\JWT::encodeAccess($userId, (int) ($user->toArray()['token_version'] ?? 1));
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
    }

    protected function refreshDatabase(string $migrationsDir, string $dbConfig = null): void
    {
        if ($dbConfig !== null) {
            \Siro\Core\Database::configure($dbConfig);
        }

        $files = glob(rtrim($migrationsDir, '/') . '/*.php');
        if (!$files) return;

        sort($files);

        // Drop all tables first
        try {
            $pdo = \Siro\Core\Database::connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS \"{$table}\"");
                }
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                }
            }
        } catch (\Throwable) {
        }

        // Run all migrations
        foreach ($files as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                try {
                    $migration->up();
                } catch (\Throwable) {
                }
            }
        }
    }

    protected function assertArraySubset(array $subset, array $full): void
    {
        foreach ($subset as $key => $value) {
            $this->assertArrayHasKey($key, $full, "Missing key: {$key}");
            if (is_array($value)) {
                $this->assertArraySubset($value, $full[$key]);
            } else {
                $this->assertSame($value, $full[$key], "Mismatch at key: {$key}");
            }
        }
    }
}
