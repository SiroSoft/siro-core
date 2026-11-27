<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Siro\Core\Auth\OAuth2\Entities\AuthCodeEntity;
use Siro\Core\Database;

/**
 * League authorization code repository backed by the `oauth_auth_codes` table.
 *
 * Codes are single-use: league revokes a code as soon as it is exchanged for
 * tokens, so replaying a stolen code fails with `invalid_grant`.
 */
final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    private static string $table = 'oauth_auth_codes';

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $scopes = [];
        foreach ($authCodeEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        Database::execute(
            'INSERT INTO ' . self::$table
            . ' (id, client_id, user_id, redirect_uri, scopes, expires_at, revoked, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $authCodeEntity->getIdentifier(),
                $authCodeEntity->getClient()->getIdentifier(),
                $authCodeEntity->getUserIdentifier(),
                $authCodeEntity->getRedirectUri(),
                implode(',', $scopes),
                $authCodeEntity->getExpiryDateTime()->getTimestamp(),
                0,
                time(),
            ],
        );
    }

    public function revokeAuthCode(string $codeId): void
    {
        Database::execute(
            'UPDATE ' . self::$table . ' SET revoked = 1 WHERE id = ?',
            [$codeId],
        );
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $rows = Database::select(
            'SELECT revoked FROM ' . self::$table . ' WHERE id = ? LIMIT 1',
            [$codeId],
        );
        if ($rows === []) {
            return true;
        }
        /** @var array<string, string|int|null> $row */
        $row = $rows[0];
        return (int) ($row['revoked'] ?? 0) !== 0;
    }

    /**
     * Create the oauth_auth_codes table.
     */
    public static function createTable(): void
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id VARCHAR(64) PRIMARY KEY,
                    client_id VARCHAR(64) NOT NULL,
                    user_id VARCHAR(64),
                    redirect_uri VARCHAR(2048),
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    expires_at INTEGER NOT NULL,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        } elseif ($driver === 'mysql') {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id VARCHAR(64) PRIMARY KEY,
                    client_id VARCHAR(64) NOT NULL,
                    user_id VARCHAR(64),
                    redirect_uri VARCHAR(2048),
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    expires_at INT NOT NULL,
                    revoked TINYINT NOT NULL DEFAULT 0,
                    created_at INT NOT NULL,
                    INDEX idx_oauth_auth_codes_client (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        } else {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id TEXT PRIMARY KEY,
                    client_id TEXT NOT NULL,
                    user_id TEXT,
                    redirect_uri TEXT,
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    expires_at INTEGER NOT NULL,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        }
    }
}
