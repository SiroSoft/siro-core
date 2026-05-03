<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Siro\Core\DB\QueryBuilder;

/**
 * PDO connection manager and raw query executor.
 *
 * Manages a singleton PDO connection with support for MySQL, PostgreSQL,
 * and SQLite. Tracks query execution time for slow query logging,
 * captures all queries for debug traces, and supports nested transactions
 * via savepoints.
 *
 * @package Siro\Core
 */
final class Database
{
    /** @var array<string, mixed> */
    private static array $config = [];
    private static ?PDO $pdo = null;
    private static int $queryCacheTtl = 0;
    private static int $transactionDepth = 0;
    private static int $slowQueryThreshold = 100;
    /** @var array<int, array{sql:string,bindings:array<string,mixed>,time_ms:float,rows:int}> */
    private static array $capturedQueries = [];

    /**
     * @param array<string, mixed> $config
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$slowQueryThreshold = max(0, (int) ($config['slow_query_threshold'] ?? 100));
        self::$capturedQueries = [];
    }

    /** @return array<int, array{sql:string,bindings:array<string,mixed>,time_ms:float,rows:int}> */
    public static function getCapturedQueries(): array
    {
        return self::$capturedQueries;
    }

    public static function resetCapturedQueries(): void
    {
        self::$capturedQueries = [];
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
            'pgsql', 'postgres', 'postgresql' => sprintf('pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=%s\'', $host, $port, $database, $charset),
            'sqlite' => sprintf('sqlite:%s', $database === ':memory:' ? ':memory:' : self::resolveSqlitePath($database)),
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
     * Resolve SQLite database path (convert relative to absolute)
     */
    private static function resolveSqlitePath(string $path): string
    {
        // If already absolute path, return as-is
        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($path, '/')) {
            return $path; // Unix absolute path
        }
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Z]:\\\\/i', $path)) {
            return $path; // Windows absolute path
        }
        
        // Convert relative path to absolute based on project root
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        if (defined('SIRO_BASE_PATH')) {
            $basePath = SIRO_BASE_PATH;
        }
        
        // Remove leading ./ if present
        $relativePath = ltrim($path, './');
        
        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
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
        $start = microtime(true);
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $elapsed = (microtime(true) - $start) * 1000;

        $rows = 0;
        try {
            $rows = $stmt->rowCount();
        } catch (\Throwable) {
        }

        self::$capturedQueries[] = [
            'sql' => $sql,
            'bindings' => $params,
            'time_ms' => round($elapsed, 2),
            'rows' => $rows,
        ];

        if ($elapsed > self::$slowQueryThreshold) {
            Logger::error(new \RuntimeException(sprintf(
                'Slow query (%.2fms): %s | Bindings: %s',
                $elapsed,
                $sql,
                json_encode($params, JSON_UNESCAPED_UNICODE)
            )));
        }

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
