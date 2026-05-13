<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use PDO;
use PDOStatement;
use RuntimeException;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Logger;
use Siro\Core\DB\QueryBuilder;

final class DatabaseInstance implements DatabaseInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $configs = [];
    private string $defaultConnection = 'default';
    /** @var array<string, PDO> */
    private array $pdoInstances = [];
    private int $queryCacheTtl = 0;
    private int $transactionDepth = 0;
    private int $slowQueryThreshold = 100;
    /** @var array<int, array{sql:string,bindings:array<int|string,mixed>,time_ms:float,rows:int,connection:string}> */
    private array $capturedQueries = [];

    /** @param array<string, mixed> $config */
    public function configure(array $config, string $name = 'default'): void
    {
        $this->configs[$name] = $config;
        $this->slowQueryThreshold = max(0, (int) ($config['slow_query_threshold'] ?? 100));

        if ($name === $this->defaultConnection) {
            $this->capturedQueries = [];
        }
    }

    public function default(string $name): void
    {
        $this->defaultConnection = $name;
    }

    public function connection(?string $name = null): PDO
    {
        $name ??= $this->defaultConnection;

        if (isset($this->pdoInstances[$name])) {
            $pdo = $this->pdoInstances[$name];
            try {
                $pdo->query('SELECT 1');
            } catch (\Throwable) {
                unset($this->pdoInstances[$name]);
            }
            if (isset($this->pdoInstances[$name])) {
                return $pdo;
            }
        }

        $config = $this->configs[$name] ?? throw new RuntimeException("Database connection '{$name}' is not configured.");

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
            'sqlite' => sprintf('sqlite:%s', $database === ':memory:' ? ':memory:' : $this->resolveSqlitePath($database)),
            default => throw new RuntimeException(sprintf('Unsupported DB driver: %s', $driver)),
        };

        if ($driver === 'sqlite') {
            $this->pdoInstances[$name] = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $this->pdoInstances[$name] = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdoInstances[$name];
    }

    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->pdoInstances = [];
            $this->configs = [];
            $this->capturedQueries = [];
            $this->transactionDepth = 0;
            return;
        }
        unset($this->pdoInstances[$name], $this->configs[$name]);
    }

    public function purgeAll(): void
    {
        $this->pdoInstances = [];
        $this->configs = [];
        $this->capturedQueries = [];
        $this->transactionDepth = 0;
        $this->defaultConnection = 'default';
        $this->queryCacheTtl = 0;
        QueryBuilder::resetDriverNames();
    }

    /** @return array<int, string> */
    public function connections(): array
    {
        return array_keys($this->configs);
    }

    private function resolveSqlitePath(string $path): string
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
    public function getCapturedQueries(): array
    {
        return $this->capturedQueries;
    }

    public function resetCapturedQueries(): void
    {
        $this->capturedQueries = [];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = [], ?string $connection = null): array
    {
        $ttl = $this->pullQueryCacheTtl();
        if ($ttl > 0) {
            $cacheKey = $this->queryCacheKey('select', $sql, $params);
            $cached = Cache::remember($cacheKey, $ttl, function () use ($sql, $params, $connection): array {
                $stmt = $this->prepareAndExecute($sql, $params, $connection);
                return $stmt->fetchAll();
            });
            return $cached;
        }
        $stmt = $this->prepareAndExecute($sql, $params, $connection);
        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = [], ?string $connection = null): ?array
    {
        $ttl = $this->pullQueryCacheTtl();
        if ($ttl > 0) {
            $cacheKey = $this->queryCacheKey('first', $sql, $params);
            $cached = Cache::remember($cacheKey, $ttl, function () use ($sql, $params, $connection): ?array {
                $stmt = $this->prepareAndExecute($sql, $params, $connection);
                $row = $stmt->fetch();
                return $row !== false ? $row : null;
            });
            return $cached;
        }
        $stmt = $this->prepareAndExecute($sql, $params, $connection);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = [], ?string $connection = null): int
    {
        $stmt = $this->prepareAndExecute($sql, $params, $connection);
        return $stmt->rowCount();
    }

    public function cache(int $ttl = 60): static
    {
        $this->queryCacheTtl = max(0, $ttl);
        return $this;
    }

    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return (new QueryBuilder($table))->connection($connection ?? $this->defaultConnection);
    }

    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        $connName = $connection ?? $this->defaultConnection;
        $pdo = $this->connection($connName);
        $isRoot = $this->transactionDepth === 0;
        $savepoint = 'siro_sp_' . $connName . '_' . $this->transactionDepth;

        if ($isRoot) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $this->transactionDepth++;
        try {
            $result = $callback();
            $this->transactionDepth--;
            if ($isRoot) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $e) {
            $this->transactionDepth = max(0, $this->transactionDepth - 1);
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
    public function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:', ?string $connection = null): array
    {
        $ttl = max(0, $ttl);
        if ($ttl === 0) {
            return $this->select($sql, $params, $connection);
        }
        $normalizedPrefix = rtrim(trim($cachePrefix), ':') . ':';
        $cacheKey = $normalizedPrefix . sha1('qb_select|' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = Cache::remember($cacheKey, $ttl, function () use ($sql, $params, $connection): array {
            $stmt = $this->prepareAndExecute($sql, $params, $connection);
            return $stmt->fetchAll();
        });
        return $cached;
    }

    /** @param array<int|string, mixed> $params */
    private function prepareAndExecute(string $sql, array $params, ?string $connection = null): PDOStatement
    {
        $start = microtime(true);
        $stmt = $this->connection($connection)->prepare($sql);
        $stmt->execute($params);
        $elapsed = (microtime(true) - $start) * 1000;

        $rows = 0;
        try {
            $rows = $stmt->rowCount();
        } catch (\Throwable $e) {
            Logger::error(new \RuntimeException('Failed to get rowCount: ' . $e->getMessage()));
        }

        $connName = $connection ?? $this->defaultConnection;
        $this->capturedQueries[] = [
            'sql' => $sql,
            'bindings' => $params,
            'time_ms' => round($elapsed, 2),
            'rows' => $rows,
            'connection' => $connName,
        ];

        if ($elapsed > $this->slowQueryThreshold) {
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

    private function pullQueryCacheTtl(): int
    {
        $ttl = $this->queryCacheTtl;
        $this->queryCacheTtl = 0;
        return $ttl;
    }

    /** @param array<int|string, mixed> $params */
    private function queryCacheKey(string $type, string $sql, array $params): string
    {
        return 'db:' . sha1($type . '|' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
