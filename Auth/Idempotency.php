<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use RuntimeException;
use Siro\Core\Database;

/**
 * Idempotency key handler for preventing duplicate operations.
 *
 * Stores responses for duplicate requests within a TTL window.
 * Only stores successful (2xx) responses.
 *
 * Usage:
 *   $idempotency = new Idempotency();
 *   $idempotency->setKey($key, $ttl);
 *   if ($idempotency->isDuplicate()) {
 *       return $idempotency->getStoredResponse();
 *   }
 *   // ... process request ...
 *   $idempotency->storeResponse($responseData);
 */
final class Idempotency
{
    private static string $table = 'idempotency_keys';
    private ?string $currentKey = null;
    private int $ttl = 86400;
    private int $userId = 0;
    private ?string $hash = null;
    private bool $isDuplicate = false;
    /** @var array<string, mixed>|null */
    private ?array $storedResponse = null;

    public function __construct(int $ttl = 86400)
    {
        $this->ttl = max(1, $ttl);
    }

    /**
     * Set the idempotency key and check for duplicates.
     *
     * @param string $key The idempotency key (e.g., UUID from client)
     * @param int $userId The user ID for per-user isolation (0 for anonymous)
     * @param string $method HTTP method (POST, PUT, PATCH)
     */
    public function setKey(string $key, int $userId = 0, string $method = 'POST'): void
    {
        $this->currentKey = trim($key);
        $this->userId = max(0, $userId);

        if ($this->currentKey === '') {
            $this->isDuplicate = false;
            return;
        }

        $this->hash = hash('sha256', $this->currentKey . '|' . $this->userId . '|' . $method);

        $existing = Database::select(
            "SELECT id, response_data, created_at FROM " . self::$table . " WHERE hash = ? AND expires_at > ? LIMIT 1",
            [$this->hash, time()]
        );

        if (!empty($existing)) {
            $this->isDuplicate = true;
            $data = Database::select(
                "SELECT response_data FROM " . self::$table . " WHERE id = ?",
                [$existing[0]['id']]
            );
            $storedRaw = $data[0]['response_data'] ?? '';
            if (is_string($storedRaw) && $storedRaw !== '') {
                $decoded = json_decode($storedRaw, true);
                if (is_array($decoded)) {
                    $this->storedResponse = $decoded;
                }
            }
        } else {
            $this->isDuplicate = false;
        }
    }

    /**
     * Check if the current key is a duplicate.
     */
    public function isDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    /**
     * Get the stored response for a duplicate request.
     *
     * @return array<string, mixed>|null
     */
    public function getStoredResponse(): ?array
    {
        if (is_array($this->storedResponse)) {
            return $this->storedResponse;
        }
        return null;
    }

    /**
     * Store the response data for this idempotency key.
     *
     * @param array<string, mixed> $responseData
     */
    public function storeResponse(array $responseData): void
    {
        if ($this->hash === null || $this->currentKey === null) {
            return;
        }

        $expiresAt = time() + $this->ttl;
        $responseJson = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Database::execute(
            "INSERT INTO " . self::$table . " (hash, idempotency_key, user_id, response_data, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(hash) DO UPDATE SET
             response_data = EXCLUDED.response_data,
             created_at = EXCLUDED.created_at,
             expires_at = EXCLUDED.expires_at",
            [$this->hash, $this->currentKey, $this->userId, $responseJson, time(), $expiresAt]
        );
    }

    /**
     * Clear the current key state.
     */
    public function clear(): void
    {
        $this->currentKey = null;
        $this->hash = null;
        $this->isDuplicate = false;
        $this->storedResponse = null;
    }

    /**
     * Create the idempotency table.
     */
    public static function createTable(): void
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            Database::execute("
                CREATE TABLE IF NOT EXISTS " . self::$table . " (
                    id SERIAL PRIMARY KEY,
                    hash VARCHAR(64) UNIQUE NOT NULL,
                    idempotency_key VARCHAR(255) NOT NULL,
                    user_id INTEGER DEFAULT 0,
                    response_data TEXT,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                )
            ");
            Database::execute("CREATE INDEX IF NOT EXISTS idx_idempotency_hash_expires ON " . self::$table . " (hash, expires_at)");
        } else {
            Database::execute("
                CREATE TABLE IF NOT EXISTS " . self::$table . " (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hash VARCHAR(64) UNIQUE NOT NULL,
                    idempotency_key VARCHAR(255) NOT NULL,
                    user_id INTEGER DEFAULT 0,
                    response_data TEXT,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                )
            ");
            Database::execute("CREATE INDEX IF NOT EXISTS idx_idempotency_hash_expires ON " . self::$table . " (hash, expires_at)");
        }
    }

    /**
     * Cleanup expired idempotency keys.
     *
     * @return int Number of keys deleted
     */
    public static function cleanup(?int $olderThan = null): int
    {
        $cutoff = $olderThan ?? (time() - (7 * 86400));
        $result = Database::execute(
            "DELETE FROM " . self::$table . " WHERE expires_at < ?",
            [$cutoff]
        );
        return $result;
    }
}