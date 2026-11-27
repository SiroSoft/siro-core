<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Siro\Core\Auth\OAuth2\Entities\AccessTokenEntity;
use Siro\Core\Database;

/**
 * League access token repository backed by the `oauth_access_tokens` table.
 *
 * The row exists so revocation can be checked on every resource request
 * (`isAccessTokenRevoked()` is called by league's `BearerTokenValidator`).
 * The token value itself is a league RS256 JWT — the table only stores its
 * `jti`, never the token string.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    private static string $table = 'oauth_access_tokens';

    /**
     * @param ScopeEntityInterface[] $scopes
     */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null,
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null && $userIdentifier !== '') {
            $token->setUserIdentifier($userIdentifier);
        }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $scopes = [];
        foreach ($accessTokenEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        Database::execute(
            'INSERT INTO ' . self::$table
            . ' (id, client_id, user_id, scopes, expires_at, revoked, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $accessTokenEntity->getIdentifier(),
                $accessTokenEntity->getClient()->getIdentifier(),
                $accessTokenEntity->getUserIdentifier(),
                implode(',', $scopes),
                $accessTokenEntity->getExpiryDateTime()->getTimestamp(),
                0,
                time(),
            ],
        );
    }

    public function revokeAccessToken(string $tokenId): void
    {
        Database::execute(
            'UPDATE ' . self::$table . ' SET revoked = 1 WHERE id = ?',
            [$tokenId],
        );
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $rows = Database::select(
            'SELECT revoked, expires_at FROM ' . self::$table . ' WHERE id = ? LIMIT 1',
            [$tokenId],
        );
        if ($rows === []) {
            // Unknown token id: fail closed — league treats it as revoked.
            return true;
        }
        /** @var array<string, string|int|null> $row */
        $row = $rows[0];
        return (int) ($row['revoked'] ?? 0) !== 0;
    }

    /**
     * Create the oauth_access_tokens table.
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
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    expires_at INT NOT NULL,
                    revoked TINYINT NOT NULL DEFAULT 0,
                    created_at INT NOT NULL,
                    INDEX idx_oauth_access_tokens_client (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        } else {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    id TEXT PRIMARY KEY,
                    client_id TEXT NOT NULL,
                    user_id TEXT,
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    expires_at INTEGER NOT NULL,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        }
    }
}
