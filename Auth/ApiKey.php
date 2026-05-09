<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use RuntimeException;
use Siro\Core\Database;

/**
 * API Key authentication for external developers.
 *
 * Provides simple token-based auth without JWT complexity.
 * Keys are stored in database with metadata.
 *
 * Usage:
 *   $apiKey = new ApiKey();
 *   $apiKey->create('External Partner', 'read,write');
 *   $apiKey->validate($token);
 *   $apiKey->revoke($token);
 */
final class ApiKey
{
    private static string $table = 'api_keys';

    /**
     * Generate a new API key.
     *
     * @param string $name Human-readable name (e.g., "External Partner A")
     * @param string $scopes Comma-separated scopes: 'read', 'write', 'admin'
     * @param int|null $userId Associated user ID (0 for system-level)
     * @param int $expiresIn Days until expiration (0 = never)
     * @return array{token: string, name: string, scopes: string, created_at: string}
     */
    public static function create(string $name, string $scopes = 'read', ?int $userId = null, int $expiresIn = 0): array
    {
        $token = self::generateToken();
        $hashedToken = hash('sha256', $token);
        $scopes = strtolower(trim($scopes));
        $createdAt = time();
        $expiresAt = $expiresIn > 0 ? $createdAt + ($expiresIn * 86400) : 0;

        Database::execute(
            "INSERT INTO " . self::$table . " (name, token_hash, scopes, user_id, created_at, expires_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$name, $hashedToken, $scopes, $userId ?? 0, $createdAt, $expiresAt, null]
        );

        return [
            'token' => $token,
            'name' => $name,
            'scopes' => $scopes,
            'created_at' => date('Y-m-d H:i:s', $createdAt),
            'expires_at' => $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : null,
        ];
    }

    /**
     * Validate an API key token.
     *
     * @return array<string, mixed>|null Returns key data if valid, null if invalid/expired
     */
    public static function validate(string $token): ?array
    {
        $hashedToken = hash('sha256', $token);
        $now = time();

        $rows = Database::select(
            "SELECT id, name, scopes, user_id, created_at, expires_at FROM " . self::$table . "
             WHERE token_hash = ? LIMIT 1",
            [$hashedToken]
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $key */
        $key = $rows[0];
        $expiresAt = (int) ($key['expires_at'] ?? 0);

        if ($expiresAt > 0 && $expiresAt < $now) {
            return null;
        }

        Database::execute(
            "UPDATE " . self::$table . " SET last_used_at = ? WHERE id = ?",
            [$now, $key['id']]
        );

        return [
            'id' => (int) $key['id'],
            'name' => (string) ($key['name'] ?? ''),
            'scopes' => (string) ($key['scopes'] ?? ''),
            'user_id' => (int) ($key['user_id'] ?? 0),
            'created_at' => (string) ($key['created_at'] ?? ''),
            'expires_at' => $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : null,
        ];
    }

    /**
     * Revoke an API key.
     *
     * @param string $token The token to revoke
     * @return bool True if revoked, false if not found
     */
    public static function revoke(string $token): bool
    {
        $hashedToken = hash('sha256', $token);
        $affected = Database::execute(
            "DELETE FROM " . self::$table . " WHERE token_hash = ?",
            [$hashedToken]
        );
        return $affected > 0;
    }

    /**
     * Revoke all API keys for a user.
     *
     * @param int $userId
     * @return int Number of keys revoked
     */
    public static function revokeAllForUser(int $userId): int
    {
        return Database::execute(
            "DELETE FROM " . self::$table . " WHERE user_id = ?",
            [$userId]
        );
    }

    /**
     * List all API keys for a user (without exposing tokens).
     *
     * @param int|null $userId
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(?int $userId = null): array
    {
        $sql = "SELECT id, name, scopes, created_at, expires_at, last_used_at FROM " . self::$table;
        $bindings = [];

        if ($userId !== null) {
            $sql .= " WHERE user_id = ?";
            $bindings[] = $userId;
        }

        $sql .= " ORDER BY created_at DESC";

        $rows = Database::select($sql, $bindings);
        $result = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'scopes' => (string) ($row['scopes'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'expires_at' => (string) ($row['expires_at'] ?? ''),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
                'is_expired' => ((int) ($row['expires_at'] ?? 0) > 0 && (int) ($row['expires_at'] ?? 0) < time()),
            ];
        }

        return $result;
    }

    /**
     * Check if a key has a specific scope.
     *
     * @param string $token
     * @param string $scope 'read', 'write', or 'admin'
     * @return bool
     */
    public static function hasScope(string $token, string $scope): bool
    {
        $keyData = self::validate($token);

        if ($keyData === null) {
            return false;
        }

        $scopes = array_map('trim', explode(',', (string) ($keyData['scopes'] ?? '')));
        $scope = strtolower(trim($scope));

        if (in_array('admin', $scopes, true)) {
            return true;
        }

        return in_array($scope, $scopes, true);
    }

    /**
     * Create the api_keys table.
     */
    public static function createTable(): void
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            Database::execute("
                CREATE TABLE IF NOT EXISTS " . self::$table . " (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    token_hash VARCHAR(64) UNIQUE NOT NULL,
                    scopes VARCHAR(100) NOT NULL DEFAULT 'read',
                    user_id INTEGER DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER DEFAULT 0,
                    last_used_at INTEGER
                )
            ");
        } elseif ($driver === 'mysql') {
            Database::execute("
                CREATE TABLE IF NOT EXISTS " . self::$table . " (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    token_hash VARCHAR(64) UNIQUE NOT NULL,
                    scopes VARCHAR(100) NOT NULL DEFAULT 'read',
                    user_id INT DEFAULT 0,
                    created_at INT NOT NULL,
                    expires_at INT DEFAULT 0,
                    last_used_at INT,
                    INDEX idx_api_keys_token_hash (token_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            Database::execute("
                CREATE TABLE IF NOT EXISTS " . self::$table . " (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    token_hash VARCHAR(64) UNIQUE NOT NULL,
                    scopes VARCHAR(100) NOT NULL DEFAULT 'read',
                    user_id INTEGER DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER DEFAULT 0,
                    last_used_at INTEGER
                )
            ");
        }
    }

    private static function generateToken(): string
    {
        return bin2hex(random_bytes(16)) . '-' . bin2hex(random_bytes(16));
    }
}
