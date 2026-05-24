<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use PDO;

final class Blueprint
{
    private string $table;
    private string $driver;
    /** @var array<int, Column> */
    private array $columns = [];
    /** @var array<int, array<string, mixed>> */
    private array $commands = [];

    public function __construct(string $table, ?string $driver = null)
    {
        $this->table = $table;
        $this->driver = $driver ?? $this->detectDriver();
    }

    public function setDriver(string $driver): void
    {
        $this->driver = $driver;
    }

    private function detectDriver(): string
    {
        try {
            $driver = \Siro\Core\Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
            return is_string($driver) ? $driver : 'mysql';
        } catch (\Throwable) {
            return 'mysql';
        }
    }

    public function id(string $name = 'id'): Column
    {
        $col = $this->addColumn('id', $name);
        $this->commands[] = ['type' => 'primary', 'columns' => [$name]];
        return $col;
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    public function text(string $name): Column
    {
        return $this->addColumn('text', $name);
    }

    public function increments(string $name = 'id'): Column
    {
        return $this->addColumn('increments', $name);
    }

    public function integer(string $name): Column
    {
        return $this->addColumn('integer', $name);
    }

    public function foreignId(string $name): Column
    {
        return $this->string($name, 36);
    }

    public function smallint(string $name): Column
    {
        return $this->addColumn('smallint', $name);
    }

    public function bigint(string $name, bool $unsigned = true): Column
    {
        return $this->addColumn('bigint', $name, ['unsigned' => $unsigned]);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->addColumn('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $name, int $precision = 10): Column
    {
        return $this->addColumn('float', $name, ['precision' => $precision]);
    }

    public function boolean(string $name): Column
    {
        return $this->addColumn('boolean', $name);
    }

    public function date(string $name): Column
    {
        return $this->addColumn('date', $name);
    }

    public function datetime(string $name): Column
    {
        return $this->addColumn('datetime', $name);
    }

    public function timestamp(string $name): Column
    {
        return $this->addColumn('timestamp', $name);
    }

    public function json(string $name): Column
    {
        return $this->addColumn('json', $name);
    }

    /**
     * ENUM column with allowed values.
     *
     * @param list<string> $allowedValues
     */
    public function enum(string $name, array $allowedValues): Column
    {
        return $this->addColumn('enum', $name, ['allowedValues' => $allowedValues]);
    }

    /**
     * JSONB column (native JSONB on PostgreSQL, JSON otherwise).
     */
    public function jsonb(string $name): Column
    {
        return $this->addColumn('jsonb', $name);
    }

    /**
     * UUID column (native UUID on PostgreSQL, CHAR(36) otherwise).
     */
    public function uuid(string $name): Column
    {
        return $this->addColumn('uuid', $name);
    }

    /**
     * IP address column (VARCHAR(45) — supports both IPv4 and IPv6).
     */
    public function ipAddress(string $name): Column
    {
        return $this->addColumn('ipAddress', $name);
    }

    /**
     * MAC address column (VARCHAR(17)).
     */
    public function macAddress(string $name): Column
    {
        return $this->addColumn('macAddress', $name);
    }

    public function timestamps(?string $createdAt = 'created_at', ?string $updatedAt = 'updated_at'): void
    {
        $this->timestamp((string) $createdAt)->useCurrent();
        $this->timestamp((string) $updatedAt)->useCurrent();
    }

    public function softDeletes(string $name = 'deleted_at'): Column
    {
        return $this->timestamp($name)->nullable();
    }

    public function rememberToken(string $name = 'remember_token'): Column
    {
        return $this->string($name, 100)->nullable();
    }

    public function foreign(string $column): ForeignKey
    {
        $fk = new ForeignKey($column);
        $this->commands[] = ['type' => 'foreign', 'fk' => $fk];
        return $fk;
    }

    /** @param array<int, string>|string $columns */
    public function unique(array|string $columns, ?string $name = null): void
    {
        $this->commands[] = [
            'type' => 'unique',
            'columns' => (array) $columns,
            'name' => $name,
        ];
    }

    /** @param array<int, string>|string $columns */
    public function index(array|string $columns, ?string $name = null): void
    {
        $this->commands[] = [
            'type' => 'index',
            'columns' => (array) $columns,
            'name' => $name,
        ];
    }

    /** @param array<int, string>|string $columns */
    public function primary(array|string $columns): void
    {
        $this->commands[] = [
            'type' => 'primary',
            'columns' => (array) $columns,
        ];
    }

    public function dropIndex(string $name): void
    {
        $this->commands[] = ['type' => 'dropIndex', 'name' => $name];
    }

    public function dropUnique(string $name): void
    {
        $this->commands[] = ['type' => 'dropUnique', 'name' => $name];
    }

    public function dropForeign(string $name): void
    {
        $this->commands[] = ['type' => 'dropForeign', 'name' => $name];
    }

    /** @return array<int, string> */
    public function compileCreate(): array
    {
        $parts = [];
        foreach ($this->columns as $col) {
            $parts[] = '  ' . $this->compileColumnDef($col);
        }

        $isMysql = $this->driver === 'mysql' || $this->driver === 'mariadb';
        $tableSql = $this->quote($this->table);
        $statements = [];

        foreach ($this->commands as $cmd) {
            if ($cmd['type'] === 'foreign') {
                $parts[] = '  ' . $this->compileCommandAsSql($cmd, true);
            } elseif ($cmd['type'] === 'primary') {
                /** @var array<int, string> $primaryColumns */
                $primaryColumns = (array) ($cmd['columns'] ?? []);
                // Skip 'primary' command when all columns already have type 'id' (inline PRIMARY KEY)
                $alreadyHasInline = true;
                foreach ($primaryColumns as $pc) {
                    $found = false;
                    foreach ($this->columns as $col) {
                        if ($col->name === $pc && $col->type === 'id') {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $alreadyHasInline = false;
                        break;
                    }
                }
                if (!$alreadyHasInline) {
                    $parts[] = '  PRIMARY KEY (' . implode(', ', array_map(fn(string $c): string => $this->quote($c), $primaryColumns)) . ')';
                }
            } elseif ($cmd['type'] === 'unique' || $cmd['type'] === 'index') {
                if ($isMysql) {
                    $parts[] = '  ' . $this->compileCommandAsSql($cmd, true);
                } else {
                    $stmts = $this->compileCommandAsSql($cmd, false);
                    if ($stmts !== null) {
                        $statements[] = $stmts;
                    }
                }
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$tableSql} (\n" . implode(",\n", $parts) . "\n)";
        if ($isMysql) {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        array_unshift($statements, $sql);
        return $statements;
    }

    public function dropColumn(string $name): void
    {
        $this->commands[] = ['type' => 'dropColumn', 'column' => $name];
    }

    /** @return array<int, string> */
    public function compileAlter(): array
    {
        $parts = [];
        $tableSql = $this->quote($this->table);
        $isMysql = $this->driver === 'mysql' || $this->driver === 'mariadb';

        foreach ($this->columns as $col) {
            $def = $this->compileColumnDef($col, true);
            $parts[] = "ALTER TABLE {$tableSql} ADD COLUMN {$def}";
        }

        foreach ($this->commands as $cmd) {
            $type = is_string($cmd['type'] ?? null) ? $cmd['type'] : '';

            if ($type === 'dropColumn') {
                $colName = $cmd['column'] ?? '';
                $col = $this->quote(is_string($colName) ? $colName : '');
                $parts[] = "ALTER TABLE {$tableSql} DROP COLUMN {$col}";
            } elseif ($type === 'foreign') {
                $sql = $this->compileCommandAsSql($cmd, true);
                if ($sql !== null) {
                    $parts[] = "ALTER TABLE {$tableSql} ADD {$sql}";
                }
            } elseif ($type === 'unique' || $type === 'index') {
                $sql = $this->compileCommandAsSql($cmd, false);
                if ($sql !== null) {
                    $parts[] = $sql;
                }
            } elseif ($type === 'dropIndex' || $type === 'dropUnique') {
                $name = is_string($cmd['name'] ?? null) ? $this->quote($cmd['name']) : '';
                if ($isMysql) {
                    $parts[] = "ALTER TABLE {$tableSql} DROP INDEX {$name}";
                } else {
                    $parts[] = "DROP INDEX {$name}";
                }
            } elseif ($type === 'dropForeign') {
                $name = is_string($cmd['name'] ?? null) ? $this->quote($cmd['name']) : '';
                $parts[] = "ALTER TABLE {$tableSql} DROP FOREIGN KEY {$name}";
            }
        }

        return $parts;
    }

    /** @param array<string, mixed> $params */
    private function addColumn(string $type, string $name, array $params = []): Column
    {
        $col = new Column($type, $name, $params, $this);
        $this->columns[] = $col;
        return $col;
    }

    private function compileColumnDef(Column $col, bool $isAlter = false): string
    {
        if ($col->type === 'id' && !$isAlter) {
            return match ($this->driver) {
                'pgsql' => $this->quote($col->name) . ' BIGSERIAL PRIMARY KEY',
                'sqlite' => $this->quote($col->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT',
                default => $this->quote($col->name) . ' BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            };
        }

        $type = $this->compileColumnType($col);
        $parts = [$type];

        // AFTER clause (MySQL only)
        if ($col->afterColumn !== null && $isAlter && ($this->driver === 'mysql' || $this->driver === 'mariadb')) {
            $parts[] = 'AFTER ' . $this->quote($col->afterColumn);
        }

        $defaultNotNull = !($col->type === 'id');
        foreach ($this->commands as $cmd) {
            /** @var array<int, string> $primaryColumns */
            $primaryColumns = (array) ($cmd['columns'] ?? []);
            if ($cmd['type'] === 'primary' && in_array($col->name, $primaryColumns, true)) {
                $defaultNotNull = true;
            }
        }

        if ($col->nullable === true) {
            $parts[] = 'NULL';
        } elseif ($col->nullable === false) {
            $parts[] = 'NOT NULL';
        } elseif ($defaultNotNull) {
            $parts[] = 'NOT NULL';
        }

        if ($col->useCurrent) {
            if ($this->driver === 'sqlite') {
                $parts[] = "DEFAULT (datetime('now'))";
            } else {
                $parts[] = 'DEFAULT CURRENT_TIMESTAMP';
            }
        } elseif ($col->defaultValue !== null) {
            // Boolean values must be handled explicitly — (string) false → '' (invalid SQL)
            if ($col->defaultValue === true) {
                $default = '1';
            } elseif ($col->defaultValue === false) {
                $default = '0';
            } else {
                $default = is_string($col->defaultValue) ? "'{$col->defaultValue}'" : (is_scalar($col->defaultValue) ? (string) $col->defaultValue : '');
            }
            $parts[] = "DEFAULT {$default}";
        }

        return implode(' ', $parts);
    }

    private function compileColumnType(Column $col): string
    {
        $q = $this->quote($col->name);
        $lengthParam = $col->params['length'] ?? 255;
        $precisionParam = $col->params['precision'] ?? 10;
        $scaleParam = $col->params['scale'] ?? 2;
        $unsignedParam = $col->params['unsigned'] ?? true;
        /** @var int $length */
        $length = is_numeric($lengthParam) ? (int) $lengthParam : 255;
        /** @var int $precision */
        $precision = is_numeric($precisionParam) ? (int) $precisionParam : 10;
        /** @var int $scale */
        $scale = is_numeric($scaleParam) ? (int) $scaleParam : 2;
        /** @var bool $unsigned */
        $unsigned = is_bool($unsignedParam) ? $unsignedParam : true;
        return match ($col->type) {
            'string' => $q . ' ' . match ($this->driver) {
                'sqlite' => 'TEXT',
                default => 'VARCHAR(' . $length . ')',
            },
            'text' => $q . ' TEXT',
            'integer' => $q . ' INT',
            'smallint' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'SMALLINT',
                default => 'TINYINT(1)',
            },
            'bigint' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'BIGINT',
                'sqlite' => 'INTEGER',
                default => 'BIGINT' . ($unsigned ? ' UNSIGNED' : ''),
            },
            'decimal' => sprintf('%s DECIMAL(%d,%d)', $q, $precision, $scale),
            'float' => $q . ' ' . match ($this->driver) {
                'sqlite' => 'REAL',
                default => 'FLOAT(' . $precision . ')',
            },
            'boolean' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'BOOLEAN',
                default => 'TINYINT(1)',
            },
            'date' => $q . ' ' . match ($this->driver) {
                'sqlite' => 'TEXT',
                default => 'DATE',
            },
            'datetime' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'TIMESTAMP',
                'sqlite' => 'TEXT',
                default => 'DATETIME',
            },
            'timestamp' => $q . ' ' . match ($this->driver) {
                'sqlite' => 'TEXT',
                default => 'TIMESTAMP',
            },
            'json' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                default => 'JSON',
            },
            'enum' => $this->compileEnumType($col, $q),
            'jsonb' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                default => 'JSON',
            },
            'uuid' => $q . ' ' . match ($this->driver) {
                'pgsql' => 'UUID',
                'sqlite' => 'TEXT',
                default => 'CHAR(36)',
            },
            'ipAddress' => $q . ' VARCHAR(45)',
            'macAddress' => $q . ' VARCHAR(17)',
            default => $q . ' TEXT',
        };
    }

    private function compileEnumType(Column $col, string $quotedName): string
    {
        $values = array_map(
            fn (string $v): string => "'" . addslashes($v) . "'",
            $col->allowedValues
        );
        $valuesStr = implode(', ', $values);

        return match ($this->driver) {
            'mysql' => $quotedName . " ENUM({$valuesStr})",
            'pgsql' => $quotedName . " VARCHAR(255) CHECK ({$col->name} IN ({$valuesStr}))",
            default => $quotedName . " TEXT CHECK ({$col->name} IN ({$valuesStr}))",
        };
    }

    /** @param array<string, mixed> $cmd */
    private function compileCommandAsSql(array $cmd, bool $inline): ?string
    {
        if (($cmd['type'] ?? '') === 'foreign') {
            /** @var ForeignKey $fk */
            $fk = $cmd['fk'];
            $col = $this->quote($fk->column);
            $ref = $this->quote($fk->references);
            $onTable = $this->quote($fk->onTable);
            $parts = ["FOREIGN KEY ({$col}) REFERENCES {$onTable} ({$ref})"];
            if ($fk->onDelete !== '') {
                $parts[] = "ON DELETE {$fk->onDelete}";
            }
            if ($fk->onUpdate !== '') {
                $parts[] = "ON UPDATE {$fk->onUpdate}";
            }
            return implode(' ', $parts);
        }

        if ($cmd['type'] === 'unique') {
            /** @var array<int, string> $columns */
            $columns = (array) ($cmd['columns'] ?? []);
            $cmdName = $cmd['name'] ?? null;
            $name = is_string($cmdName) ? $cmdName : ('uq_' . $this->table . '_' . implode('_', $columns));
            if ($inline) {
                return "CONSTRAINT {$name} UNIQUE (" . implode(', ', array_map(fn($c) => $this->quote($c), $columns)) . ")";
            }
            $tableSql = $this->quote($this->table);
            return "CREATE UNIQUE INDEX {$name} ON {$tableSql} (" . implode(', ', array_map(fn($c) => $this->quote($c), $columns)) . ")";
        }

        if ($cmd['type'] === 'index') {
            /** @var array<int, string> $columns */
            $columns = (array) ($cmd['columns'] ?? []);
            $cmdName = $cmd['name'] ?? null;
            $name = is_string($cmdName) ? $cmdName : ('idx_' . $this->table . '_' . implode('_', $columns));
            if ($inline) {
                return "INDEX {$name} (" . implode(', ', array_map(fn($c) => $this->quote($c), $columns)) . ")";
            }
            $tableSql = $this->quote($this->table);
            return "CREATE INDEX {$name} ON {$tableSql} (" . implode(', ', array_map(fn($c) => $this->quote($c), $columns)) . ")";
        }

        return null;
    }

    private function quote(string $identifier): string
    {
        $char = match ($this->driver) {
            'pgsql', 'postgres', 'postgresql' => '"',
            default => '`',
        };
        return $char . $identifier . $char;
    }
}

