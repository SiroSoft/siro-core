<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Siro\Core\DB\QueryBuilder;

final class Database
{
    /** @var array<string, mixed> */
    private static array $config = [];
    private static ?PDO $pdo = null;
    private static int $queryCacheTtl = 0;
    private static int $transactionDepth = 0;

    /**
     * @param array<string, mixed> $config
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (self::$config === []) {
            throw new RuntimeException('Database is not configured.');
        }

        $driver = (string) (self::$config['driver'] ?? 'mysql');
        $host = (string) (self::$config['host'] ?? '127.0.0.1');
        $port = (int) (self::$config['port'] ?? 3306);
        $database = (string) (self::$config['database'] ?? '');
        $username = (string) (self::$config['username'] ?? '');
        $password = (string) (self::$config['password'] ?? '');
        $charset = (string) (self::$config['charset'] ?? 'utf8mb4');

        $dsn = match ($driver) {
            'mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
            'pgsql', 'postgres', 'postgresql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            'sqlite' => sprintf('sqlite:%s', $database === ':memory:' ? ':memory:' : $database),
            default => throw new RuntimeException(sprintf('Unsupported DB driver: %s', $driver)),
        };

        // SQLite doesn't use username/password
        if ($driver === 'sqlite') {
            self::$pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            self::$pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$pdo;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        $ttl = self::pullQueryCacheTtl();
        if ($ttl > 0) {
            $cacheKey = self::queryCacheKey('select', $sql, $params);
            $cached = Cache::remember($cacheKey, $ttl, static function () use ($sql, $params): array {
                $stmt = self::prepareAndExecute($sql, $params);
                $rows = $stmt->fetchAll();
                return is_array($rows) ? $rows : [];
            });

            return is_array($cached) ? $cached : [];
        }

        $stmt = self::prepareAndExecute($sql, $params);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $ttl = self::pullQueryCacheTtl();
        if ($ttl > 0) {
            $cacheKey = self::queryCacheKey('first', $sql, $params);
            $cached = Cache::remember($cacheKey, $ttl, static function () use ($sql, $params): ?array {
                $stmt = self::prepareAndExecute($sql, $params);
                $row = $stmt->fetch();
                return is_array($row) ? $row : null;
            });

            return is_array($cached) ? $cached : null;
        }

        $stmt = self::prepareAndExecute($sql, $params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        self::pullQueryCacheTtl();
        $stmt = self::prepareAndExecute($sql, $params);
        return $stmt->rowCount();
    }

    public static function cache(int $ttl = 60): self
    {
        self::$queryCacheTtl = max(0, $ttl);
        return new self();
    }

    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $isRootTransaction = self::$transactionDepth === 0;
        $savepoint = 'siro_sp_' . self::$transactionDepth;

        if ($isRootTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        self::$transactionDepth++;

        try {
            $result = $callback();
            self::$transactionDepth--;

            if ($isRootTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $e) {
            self::$transactionDepth = max(0, self::$transactionDepth - 1);

            if ($isRootTransaction) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:'): array
    {
        $ttl = max(0, $ttl);
        if ($ttl === 0) {
            return self::select($sql, $params);
        }

        $normalizedPrefix = rtrim(trim($cachePrefix), ':') . ':';
        $cacheKey = $normalizedPrefix . sha1('qb_select|' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = Cache::remember($cacheKey, $ttl, static function () use ($sql, $params): array {
            $stmt = self::prepareAndExecute($sql, $params);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private static function pullQueryCacheTtl(): int
    {
        $ttl = self::$queryCacheTtl;
        self::$queryCacheTtl = 0;
        return $ttl;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function queryCacheKey(string $type, string $sql, array $params): string
    {
        return 'db:' . sha1($type . '|' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
