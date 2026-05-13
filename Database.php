<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Siro\Core\DB\QueryBuilder;

final class Database
{
    /** @var array<string, array<string, mixed>> */
    private static array $configs = [];
    private static string $defaultConnection = 'default';
    /** @var array<string, PDO> */
    private static array $pdoInstances = [];
    private static int $queryCacheTtl = 0;
    private static int $transactionDepth = 0;
    private static int $slowQueryThreshold = 100;
    /** @var array<int, array{sql:string,bindings:array<int|string,mixed>,time_ms:float,rows:int,connection:string}> */
    private static array $capturedQueries = [];

    /** @param array<string, mixed> $config */
    public static function configure(array $config, string $name = 'default'): void
    {
        self::$configs[$name] = $config;
        self::$slowQueryThreshold = max(0, (int) ($config['slow_query_threshold'] ?? 100));

        if ($name === self::$defaultConnection) {
            self::$capturedQueries = [];
        }
    }

    public static function default(string $name): void
    {
        self::$defaultConnection = $name;
    }

    public static function connection(?string $name = null): PDO
    {
        $name ??= self::$defaultConnection;

        if (isset(self::$pdoInstances[$name])) {
            $pdo = self::$pdoInstances[$name];
            try {
                $pdo->query('SELECT 1');
            } catch (\Throwable) {
                unset(self::$pdoInstances[$name]);
            }
            if (isset(self::$pdoInstances[$name])) {
                return $pdo;
            }
        }

        $config = self::$configs[$name] ?? throw new RuntimeException("Database connection '{$name}' is not configured.");

        $driver = (string) ($config['driver'] ?? 'mysql');
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? match ($driver) {
            'pgsql', 'postgres', 'postgresql' => 5432,
            'sqlite' => 0,
            default => 3306,
        });
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $dsn = match ($driver) {
            'mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
            'pgsql', 'postgres', 'postgresql' => sprintf('pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=%s\'', $host, $port, $database, $charset),
            'sqlite' => sprintf('sqlite:%s', $database === ':memory:' ? ':memory:' : self::resolveSqlitePath($database)),
            default => throw new RuntimeException(sprintf('Unsupported DB driver: %s', $driver)),
        };

        if ($driver === 'sqlite') {
            self::$pdoInstances[$name] = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            self::$pdoInstances[$name] = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$pdoInstances[$name];
    }

    public static function purge(?string $name = null): void
    {
        if ($name === null) {
            self::$pdoInstances = [];
            self::$configs = [];
            self::$capturedQueries = [];
            self::$transactionDepth = 0;
            return;
        }
        unset(self::$pdoInstances[$name], self::$configs[$name]);
    }

    public static function purgeAll(): void
    {
        self::$pdoInstances = [];
        self::$configs = [];
        self::$capturedQueries = [];
        self::$transactionDepth = 0;
        self::$defaultConnection = 'default';
        self::$queryCacheTtl = 0;
        DB\QueryBuilder::resetDriverNames();
    }

    /** @return array<int, string> */
    public static function connections(): array
    {
        return array_keys(self::$configs);
    }

    private static function resolveSqlitePath(string $path): string
    {
        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($path, '/')) {
            return $path;
        }
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Z]:\\\\/i', $path)) {
            return $path;
        }
        $basePath = defined('SIRO_BASE_PATH') ? SIRO_BASE_PATH : (defined('BASE_PATH') ? BASE_PATH : getcwd());
        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, './');
    }

    /** @return array<int, array{sql:string,bindings:array<int|string,mixed>,time_ms:float,rows:int,connection:string}> */
    public static function getCapturedQueries(): array
    {
        return self::$capturedQueries;
    }

    public static function resetCapturedQueries(): void
    {
        self::$capturedQueries = [];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $params = [], ?string $connection = null): array
    {
        $ttl = self::pullQueryCacheTtl();
        if ($ttl > 0) {
            $cacheKey = self::queryCacheKey('select', $sql, $params);
            $cached = Cache::remember($cacheKey, $ttl, static function () use ($sql, $params, $connection): array {
                $stmt = self::prepareAndExecute($sql, $params, $connection);
                return $stmt->fetchAll();
            });
            return $cached;
        }
        $stmt = self::prepareAndExecute($sql, $params, $connection);
        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $params = [], ?string $connection = null): ?array
    {
        $ttl = self::pullQueryCacheTtl();
        if ($ttl > 0) {
            $cacheKey = self::queryCacheKey('first', $sql, $params);
            $cached = Cache::remember($cacheKey, $ttl, static function () use ($sql, $params, $connection): ?array {
                $stmt = self::prepareAndExecute($sql, $params, $connection);
                $row = $stmt->fetch();
                return $row !== false ? $row : null;
            });
            return $cached;
        }
        $stmt = self::prepareAndExecute($sql, $params, $connection);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @param array<int|string, mixed> $params */
    public static function execute(string $sql, array $params = [], ?string $connection = null): int
    {
        $stmt = self::prepareAndExecute($sql, $params, $connection);
        return $stmt->rowCount();
    }

    public static function cache(int $ttl = 60): self
    {
        self::$queryCacheTtl = max(0, $ttl);
        return new self();
    }

    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return (new QueryBuilder($table))->connection($connection ?? self::$defaultConnection);
    }

    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        $connName = $connection ?? self::$defaultConnection;
        $pdo = self::connection($connName);
        $isRoot = self::$transactionDepth === 0;
        $savepoint = 'siro_sp_' . $connName . '_' . self::$transactionDepth;

        if ($isRoot) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        self::$transactionDepth++;
        try {
            $result = $callback();
            self::$transactionDepth--;
            if ($isRoot) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $e) {
            self::$transactionDepth = max(0, self::$transactionDepth - 1);
            if ($isRoot) {
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
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:', ?string $connection = null): array
    {
        $ttl = max(0, $ttl);
        if ($ttl === 0) {
            return self::select($sql, $params, $connection);
        }
        $normalizedPrefix = rtrim(trim($cachePrefix), ':') . ':';
        $cacheKey = $normalizedPrefix . sha1('qb_select|' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = Cache::remember($cacheKey, $ttl, static function () use ($sql, $params, $connection): array {
            $stmt = self::prepareAndExecute($sql, $params, $connection);
            return $stmt->fetchAll();
        });
        return $cached;
    }

    /** @param array<int|string, mixed> $params */
    private static function prepareAndExecute(string $sql, array $params, ?string $connection = null): PDOStatement
    {
        $start = microtime(true);
        $stmt = self::connection($connection)->prepare($sql);
        $stmt->execute($params);
        $elapsed = (microtime(true) - $start) * 1000;

        $rows = 0;
        try {
            $rows = $stmt->rowCount();
        } catch (\Throwable $e) {
            Logger::error(new \RuntimeException('Failed to get rowCount: ' . $e->getMessage()));
        }

        $connName = $connection ?? self::$defaultConnection;
        self::$capturedQueries[] = [
            'sql' => $sql,
            'bindings' => $params,
            'time_ms' => round($elapsed, 2),
            'rows' => $rows,
            'connection' => $connName,
        ];

        if ($elapsed > self::$slowQueryThreshold) {
            Logger::error(new \RuntimeException(sprintf(
                'Slow query (%.2fms) [%s]: %s | Bindings: %s',
                $elapsed,
                $connName,
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

    /** @param array<int|string, mixed> $params */
    private static function queryCacheKey(string $type, string $sql, array $params): string
    {
        return 'db:' . sha1($type . '|' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
