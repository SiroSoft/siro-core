<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use RuntimeException;
use Siro\Core\Database;

/**
 * OAuth2 client registry (backed by the `oauth_clients` table).
 *
 * A client is identified by its public `client_id`; the secret is shown
 * exactly once at creation time and only a bcrypt hash is stored — same
 * posture as `ApiKey` (never store a plaintext secret).
 *
 * Public clients (`is_confidential = 0`, e.g. mobile/SPA apps) have no secret:
 * the authorization_code grant relies on PKCE instead. Confidential clients
 * (server-side partner apps) must authenticate with their secret.
 *
 * Usage:
 *   $client = OAuthClient::create([
 *       'name' => 'Partner A',
 *       'redirect_uris' => ['https://partner.example/callback'],
 *       'grants' => ['authorization_code', 'refresh_token'],
 *       'scopes' => ['read'],
 *   ]);
 *   // $client['client_secret'] is only available here — store it now.
 */
final class OAuthClient
{
    private static string $table = 'oauth_clients';

    /**
     * Create a new OAuth2 client.
     *
     * @param array{
     *   name?: string,
     *   redirect_uris?: array<int, string>|string,
     *   grants?: array<int, string>|string,
     *   scopes?: array<int, string>|string,
     *   is_confidential?: bool,
     * } $data
     * @return array{
     *   client_id: string,
     *   client_secret: string,
     *   name: string,
     *   redirect_uris: array<int, string>,
     *   grants: array<int, string>,
     *   scopes: string,
     *   is_confidential: bool,
     * }
     */
    public static function create(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('OAuth client name is required.');
        }

        $redirectUrisRaw = $data['redirect_uris'] ?? [];
        /** @var array<int, string> $redirectUris */
        $redirectUris = array_values(array_filter(array_map(
            static fn (string $u): string => trim($u),
            is_array($redirectUrisRaw) ? $redirectUrisRaw : [$redirectUrisRaw],
        ), static fn (string $u): bool => $u !== ''));

        $grantsRaw = $data['grants'] ?? ['authorization_code', 'client_credentials', 'refresh_token'];
        /** @var array<int, string> $grants */
        $grants = array_values(array_unique(array_filter(array_map(
            static fn (string $g): string => strtolower(trim($g)),
            is_array($grantsRaw) ? $grantsRaw : [$grantsRaw],
        ), static fn (string $g): bool => $g !== '')));
        if ($grants === []) {
            throw new RuntimeException('OAuth client needs at least one grant type.');
        }

        $scopesRaw = $data['scopes'] ?? '';
        $scopes = is_array($scopesRaw)
            ? implode(',', array_map(static fn (string $s): string => strtolower(trim($s)), $scopesRaw))
            : strtolower(trim($scopesRaw));

        $isConfidential = (bool) ($data['is_confidential'] ?? true);
        if ($isConfidential && $redirectUris === [] && in_array('authorization_code', $grants, true)) {
            throw new RuntimeException('authorization_code clients need at least one redirect URI.');
        }

        $clientId = 'oauth_' . bin2hex(random_bytes(16));
        $secret = $isConfidential ? bin2hex(random_bytes(24)) : '';
        $secretHash = $secret !== '' ? password_hash($secret, PASSWORD_DEFAULT) : '';

        Database::execute(
            'INSERT INTO ' . self::$table
            . ' (client_id, name, secret_hash, redirect_uris, grants, scopes, is_confidential, revoked, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $clientId,
                $name,
                $secretHash,
                json_encode($redirectUris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($grants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $scopes,
                $isConfidential ? 1 : 0,
                0,
                time(),
            ],
        );

        return [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'name' => $name,
            'redirect_uris' => $redirectUris,
            'grants' => $grants,
            'scopes' => $scopes,
            'is_confidential' => $isConfidential,
        ];
    }

    /** @return array<string, string|int|null>|null */
    public static function findById(string $clientId): ?array
    {
        $rows = Database::select(
            'SELECT client_id, name, secret_hash, redirect_uris, grants, scopes,'
            . ' is_confidential, revoked, created_at FROM ' . self::$table
            . ' WHERE client_id = ? LIMIT 1',
            [trim($clientId)],
        );
        if ($rows === []) {
            return null;
        }

        /** @var array<string, string|int|null> $row */
        $row = $rows[0];
        return $row;
    }

    public static function validateSecret(string $clientId, string $secret): bool
    {
        $row = self::findById($clientId);
        if ($row === null || (int) ($row['revoked'] ?? 0) !== 0) {
            return false;
        }
        $hash = (string) ($row['secret_hash'] ?? '');
        if ($hash === '') {
            return false;
        }
        return password_verify($secret, $hash);
    }

    public static function revoke(string $clientId): bool
    {
        return Database::execute(
            'UPDATE ' . self::$table . ' SET revoked = 1 WHERE client_id = ?',
            [trim($clientId)],
        ) > 0;
    }

    /** @return array<int, array<string, string|int|null>> */
    public static function list(): array
    {
        $rows = Database::select(
            'SELECT client_id, name, redirect_uris, grants, scopes, is_confidential,'
            . ' revoked, created_at FROM ' . self::$table . ' ORDER BY created_at DESC',
        );

        $result = [];
        foreach ($rows as $row) {
            /** @var array<string, string|int|null> $row */
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param array<string, string|int|null> $row
     * @return array<int, string>
     */
    public static function redirectUris(array $row): array
    {
        $decoded = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);
        return is_array($decoded) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $decoded)) : [];
    }

    /**
     * @param array<string, string|int|null> $row
     * @return array<int, string>
     */
    public static function grants(array $row): array
    {
        $decoded = json_decode((string) ($row['grants'] ?? '[]'), true);
        return is_array($decoded) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $decoded)) : [];
    }

    /**
     * @param array<string, string|int|null> $row
     * @return array<int, string>
     */
    public static function scopes(array $row): array
    {
        $raw = trim((string) ($row['scopes'] ?? ''));
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (string $s): string => strtolower(trim($s)),
            explode(',', $raw),
        ), static fn (string $s): bool => $s !== ''));
    }

    /**
     * Create the oauth_clients table.
     */
    public static function createTable(): void
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    client_id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    secret_hash VARCHAR(255) NOT NULL DEFAULT \'\',
                    redirect_uris TEXT NOT NULL DEFAULT \'[]\',
                    grants TEXT NOT NULL DEFAULT \'[]\',
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    is_confidential INTEGER NOT NULL DEFAULT 1,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        } elseif ($driver === 'mysql') {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    client_id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    secret_hash VARCHAR(255) NOT NULL DEFAULT \'\',
                    redirect_uris TEXT NOT NULL,
                    grants TEXT NOT NULL,
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    is_confidential TINYINT NOT NULL DEFAULT 1,
                    revoked TINYINT NOT NULL DEFAULT 0,
                    created_at INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        } else {
            Database::execute('
                CREATE TABLE IF NOT EXISTS ' . self::$table . ' (
                    client_id TEXT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    secret_hash VARCHAR(255) NOT NULL DEFAULT \'\',
                    redirect_uris TEXT NOT NULL DEFAULT \'[]\',
                    grants TEXT NOT NULL DEFAULT \'[]\',
                    scopes VARCHAR(255) NOT NULL DEFAULT \'\',
                    is_confidential INTEGER NOT NULL DEFAULT 1,
                    revoked INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )
            ');
        }
    }
}
