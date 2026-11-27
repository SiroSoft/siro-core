<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Siro\Core\Auth\OAuth2\Entities\RefreshTokenEntity;
use Siro\Core\Database;

/**
 * League refresh token repository backed by the `oauth_refresh_tokens` table.
 *
 * League rotates refresh tokens: when a refresh token is used, the grant
 * revokes it and issues a fresh one. Reusing an already-revoked refresh
 * token is therefore rejected — replaying a stolen refresh token does not
 * yield new access tokens.
 */
final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private static string $table = 'oauth_refresh_tokens';

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        Database::execute(
            'INSERT INTO ' . self::$table
            . ' (id, access_token_id, expires_at, revoked, created_at)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [
                $refreshTokenEntity->getIdentifier(),
                $refreshTokenEntity->getAccessToken()->getIdentifier(),
                $refreshTokenEntity->getExpiryDateTime()->getTimestamp(),
                0,
                time(),
            ],
        );
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        Database::execute(
            'UPDATE ' . self::$table . ' SET revoked = 1 WHERE id = ?',
            [$tokenId],
        );
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $rows = Database::select(
            'SELECT revoked FROM ' . self::$table . ' WHERE id = ? LIMIT 1',
            [$tokenId],
        );
        if ($rows === []) {
            return true;
        }
        /** @var array<string, string|int|null> $row */
        $row = $rows[0];
        return (int) ($row['revoked'] ?? 0) !== 0;
    }

    /**
     * Create the oauth_refresh_tokens table.
     */
    public static function createTable(): void
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id VARCHAR(64) PRIMARY KEY,
                    access_token_id VARCHAR(64) NOT NULL,
                    expires_at INTEGER NOT NULL,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        } elseif ($driver === 'mysql') {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id VARCHAR(64) PRIMARY KEY,
                    access_token_id VARCHAR(64) NOT NULL,
                    expires_at INT NOT NULL,
                    revoked TINYINT NOT NULL DEFAULT 0,
                    created_at INT NOT NULL,
                    INDEX idx_oauth_refresh_tokens_access (access_token_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        } else {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id TEXT PRIMARY KEY,
                    access_token_id TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        }
    }
}
