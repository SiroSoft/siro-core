<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;
use Siro\Core\Env;

final class DbWhyCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const BOLD = "\033[1m";
    private const GRAY = "\033[90m";

    private const SLOW_THRESHOLD_MS = 100;

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $queryHash = trim((string) ($args[0] ?? ''));
        $listSlow = in_array('--slow', $args, true);

        if ($queryHash === '' && !$listSlow) {
            $this->write('  Usage: php siro db:why <query_hash>');
            $this->write('  ' . self::GRAY . '  php siro db:why a1b2c3d4' . self::RESET);
            $this->write('  ' . self::GRAY . '  php siro db:why --slow        (list slow queries)' . self::RESET);
            return 1;
        }

        if ($listSlow) {
            return $this->listSlowQueries();
        }

        // Find query by hash from trace files
        $tracesDir = $this->getTracesDir($this->basePath);
        $traceFiles = $this->findTraceFiles($tracesDir);

        if ($traceFiles === []) {
            $this->write('  ' . self::YELLOW . 'No traces found. Enable APP_DEBUG=true to capture traces.' . self::RESET);
            return 1;
        }

        $matchedQuery = null;
        $matchedTrace = null;

        foreach ($traceFiles as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) continue;

            $queries = $data['queries'] ?? [];
            if (!is_array($queries)) continue;

            foreach ($queries as $q) {
                if (!is_array($q)) continue;
                $sql = is_string($q['sql'] ?? null) ? $q['sql'] : '';
                $hash = substr(sha1($sql), 0, 8);
                if ($hash === $queryHash) {
                    $matchedQuery = $q;
                    $matchedTrace = $data;
                    break 2;
                }
            }
        }

        if ($matchedQuery === null) {
            $this->write('  ' . self::YELLOW . 'Query not found. Hash: ' . $queryHash . self::RESET);
            $this->write('  ' . self::GRAY . 'Run php siro log:trace --slow to find slow query hashes' . self::RESET);
            return 1;
        }

        /** @var array<string, mixed> $matchedQuery */
        /** @var array<string, mixed> $matchedTrace */
        $this->analyzeQuery($matchedQuery, $matchedTrace);

        return 0;
    }

    private function listSlowQueries(): int
    {
        $tracesDir = $this->getTracesDir($this->basePath);
        $traceFiles = $this->findTraceFiles($tracesDir);

        if ($traceFiles === []) {
            $this->write('  ' . self::YELLOW . 'No traces found.' . self::RESET);
            return 1;
        }

        $slowQueries = [];

        foreach ($traceFiles as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) continue;

            $queries = $data['queries'] ?? [];
            if (!is_array($queries)) continue;

            foreach ($queries as $q) {
                if (!is_array($q)) continue;
                $timeMs = is_numeric($q['time_ms'] ?? null) ? (float) $q['time_ms'] : 0.0;
                if ($timeMs >= self::SLOW_THRESHOLD_MS) {
                    $sql = is_string($q['sql'] ?? null) ? $q['sql'] : '';
                    $hash = substr(sha1($sql), 0, 8);
                    $slowQueries[] = [
                        'hash' => $hash,
                        'time_ms' => $timeMs,
                        'sql' => $sql,
                        'trace_id' => $data['trace_id'] ?? '',
                        'method' => $data['method'] ?? '',
                        'path' => $data['path'] ?? '',
                    ];
                }
            }
        }

        if ($slowQueries === []) {
            $this->write('  ' . self::GREEN . '✓ No slow queries found (threshold: ' . self::SLOW_THRESHOLD_MS . 'ms)' . self::RESET);
            return 0;
        }

        usort($slowQueries, fn (array $a, array $b): int => ($b['time_ms'] <=> $a['time_ms']));

        $this->write('');
        $this->write('  ' . self::BOLD . 'Slow Queries (>' . self::SLOW_THRESHOLD_MS . 'ms)' . self::RESET);
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);

        foreach ($slowQueries as $q) {
            /** @var array{hash: string, time_ms: float|int, sql: string, trace_id: string, method: string, path: string} $q */
            $timeColor = $q['time_ms'] > 500 ? self::RED : self::YELLOW;
            $sqlShort = strlen($q['sql']) > 60 ? substr($q['sql'], 0, 57) . '...' : $q['sql'];
            $this->write('  ' . self::CYAN . $q['hash'] . self::RESET . ' ' . $timeColor . sprintf('%5.0fms', $q['time_ms']) . self::RESET . ' ' . self::GRAY . $sqlShort . self::RESET);
            $this->write('  ' . self::GRAY . '    Trace: ' . $q['method'] . ' ' . $q['path'] . ' (' . $q['trace_id'] . ')' . self::RESET);
            $this->write('    ' . self::CYAN . 'php siro db:why ' . $q['hash'] . self::RESET);
        }
        $this->write('');

        return 0;
    }

    /** @param array<string, mixed> $query
     * @param array<string, mixed> $trace */
    private function analyzeQuery(array $query, array $trace): void
    {
        $sql = $this->safeStr($query['sql'] ?? '');
        $timeMs = is_numeric($query['time_ms'] ?? null) ? (float) $query['time_ms'] : 0.0;
        $rows = is_numeric($query['rows'] ?? null) ? (int) $query['rows'] : 0;
        $hash = substr(sha1($sql), 0, 8);

        // Header
        $this->write('');
        $this->write('  ' . self::BOLD . 'Query Analysis' . self::RESET);
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);

        $durationColor = $timeMs > 500 ? self::RED : ($timeMs > 100 ? self::YELLOW : self::GREEN);
        $this->write('  Hash:     ' . self::CYAN . $hash . self::RESET);
        $this->write("  Duration: " . $durationColor . sprintf('%.0fms', $timeMs) . self::RESET);
        $this->write('  Rows:     ' . $rows);
        $this->write('');

        // SQL
        $this->write('  ' . self::BOLD . 'SQL' . self::RESET);
        $sqlFormatted = wordwrap($sql, 80, "\n    ");
        $this->write('    ' . $sqlFormatted);
        $this->write('');

        // Try to run EXPLAIN
        $this->write('  ' . self::BOLD . 'EXPLAIN' . self::RESET);
        $explainResult = $this->runExplain($sql);
        if ($explainResult !== null && $explainResult !== []) {
            $driver = $this->detectDriver();
            foreach ($explainResult as $row) {
                if ($driver === 'sqlite') {
                    $detail = is_string($row['detail'] ?? null) ? $row['detail'] : '';
                    $this->write('    ' . $this->colorizeExplainText($detail));
                } else {
                    // MySQL/PostgreSQL format: show if using index or scanning
                    // MySQL/PostgreSQL EXPLAIN format
                    foreach ($row as $key => $val) {
                        $valStr = is_scalar($val) ? (string) $val : '';
                        if (in_array(strtolower((string) $key), ['type', 'key', 'rows', 'extra', 'possible_keys', 'ref'], true)) {
                            $color = $this->getExplainColor((string) $key, $valStr);
                            $this->write('    ' . self::GRAY . $key . ': ' . self::RESET . $color . $valStr . self::RESET);
                        }
                    }
                }
            }
        } else {
            $this->write('    ' . self::GRAY . '(EXPLAIN not available — connect to database first)' . self::RESET);
        }
        $this->write('');

        // Suggestion
        $this->write('  ' . self::BOLD . 'Suggestion' . self::RESET);
        $suggestions = $this->suggestIndexes($sql, $explainResult);
        if ($suggestions !== []) {
            foreach ($suggestions as $s) {
                $this->write('    ' . self::YELLOW . '⚠ ' . self::RESET . $s);
            }
        } else {
            $this->write('    ' . self::GREEN . '✓ No issues detected' . self::RESET);
        }
        $this->write('');

        // Source trace
        $traceId = $this->safeStr($trace['trace_id'] ?? '');
        if ($traceId !== '') {
            $this->write('  ' . self::BOLD . 'Source' . self::RESET);
            $method = $this->safeStr($trace['method'] ?? 'GET');
            $path = $this->safeStr($trace['path'] ?? '/');
            $this->write('    ' . self::CYAN . $method . ' ' . $path . self::RESET);
            $this->write('    ' . self::GRAY . 'Trace: php siro log:trace ' . $traceId . self::RESET);
            $this->write('    ' . self::GRAY . 'Replay: php siro replay ' . $traceId . ' --force' . self::RESET);
        }
        $this->write('');
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);
    }

    /** @return array<int, array<string, mixed>>|null */
    private function runExplain(string $sql): ?array
    {
        try {
            // Try primary database connection
            $pdo = Database::connection();
        } catch (\Throwable) {
            // Try direct SQLite connection (common for dev/testing)
            try {
                $dbPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test.db';
                if (file_exists($dbPath)) {
                    $pdo = new \PDO('sqlite:' . $dbPath);
                } else {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        // Normalize: replace ? and :named params with literal values for EXPLAIN
        // EXPLAIN works with the SQL structure, so we just need valid syntax
        $normalized = preg_replace('/\?/', '1', $sql);
        $normalized = preg_replace('/:[a-zA-Z_]+/', '1', $normalized ?? $sql);

        try {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $explainSql = (is_string($driver) && $driver === 'sqlite') ? 'EXPLAIN QUERY PLAN ' . $normalized : 'EXPLAIN ' . $normalized;
            $stmt = $pdo->query($explainSql);
            if ($stmt === false) return null;
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int, array<string, mixed>>|null $explain
     * @return list<string> */
    private function suggestIndexes(string $sql, ?array $explain): array
    {
        $suggestions = [];

        if ($explain === null) {
            return [];
        }

        foreach ($explain as $row) {
            $type = is_string($row['type'] ?? null) ? strtolower($row['type']) : '';
            $extra = is_string($row['Extra'] ?? null) ? strtolower($row['Extra']) : (is_string($row['extra'] ?? null) ? strtolower($row['extra']) : '');
            $rowsVal = is_numeric($row['rows'] ?? null) ? (int) $row['rows'] : (is_numeric($row['ROWS'] ?? null) ? (int) $row['ROWS'] : 0);

            if ($type === 'all' || $type === 'full scan') {
                $suggestions[] = 'Full table scan detected (' . number_format($rowsVal) . ' rows scanned)';
                $suggestions[] = 'Add index on columns used in WHERE and JOIN clauses';
                // Extract table name and WHERE columns from SQL
                $tableName = $this->extractTableName($sql);
                if ($tableName !== null) {
                    $whereCols = $this->extractWhereColumns($sql);
                    if ($whereCols !== []) {
                        $indexName = 'idx_' . $tableName . '_' . implode('_', $whereCols);
                        $suggestions[] = '  Suggested: CREATE INDEX ' . $indexName . ' ON ' . $tableName . ' (' . implode(', ', $whereCols) . ')';
                    }
                }
            }

            if (str_contains($extra, 'using filesort') || str_contains($extra, 'using temporary')) {
                $suggestions[] = 'Using filesort/temporary — add composite index covering ORDER BY columns';
            }

            if (str_contains($extra, 'using where') && $type === 'all') {
                // Already covered by full scan suggestion
            }
        }

        return $suggestions;
    }

    private function extractTableName(string $sql): ?string
    {
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $m)) {
            return $m[1];
        }
        if (preg_match('/\bUPDATE\s+`?(\w+)`?/i', $sql, $m)) {
            return $m[1];
        }
        if (preg_match('/\bINSERT\s+(?:INTO\s+)?`?(\w+)`?/i', $sql, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @return list<string> */
    private function extractWhereColumns(string $sql): array
    {
        $cols = [];
        if (preg_match_all('/\bWHERE\s+(.*?)(?:\s+ORDER\s|\s+LIMIT|\s+GROUP\s|$)/is', $sql, $matches)) {
            $whereClause = $matches[1][0] ?? '';
            if (preg_match_all('/`?(\w+)`?\s*[=<>!]+\s*(?:\?|:\w+|\d+)/', $whereClause, $colMatches)) {
                foreach ($colMatches[1] as $col) {
                    $lower = strtolower($col);
                    if (!in_array($lower, ['and', 'or', 'in', 'not', 'null', 'is', 'between', 'like'], true)) {
                        $cols[] = $col;
                    }
                }
            }
        }
        return $cols;
    }

    private function detectDriver(): string
    {
        try {
            $pdo = Database::connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return is_string($driver) ? $driver : 'mysql';
        } catch (\Throwable) {
            // Try SQLite
            $dbPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test.db';
            if (file_exists($dbPath)) {
                return 'sqlite';
            }
            return 'mysql';
        }
    }

    private function colorizeExplainText(string $text): string
    {
        $lower = strtolower($text);
        $color = self::GRAY; // default
        if (str_contains($lower, 'full scan') || (str_contains($lower, 'scan') && !str_contains($lower, 'using'))) {
            $color = self::RED;
        }
        if (str_contains($lower, 'temp') || str_contains($lower, 'sort') || str_contains($lower, 'filesort')) {
            $color = self::YELLOW;
        }
        if (str_contains($lower, 'primary key') || str_contains($lower, 'unique')) {
            $color = self::GREEN;
        }
        return $color . $text . self::RESET;
    }

    private function getExplainColor(string $key, string $value): string
    {
        $lower = strtolower($value);
        return match ($key) {
            'type' => match (true) {
                str_contains($lower, 'all') || str_contains($lower, 'full') => self::RED,
                str_contains($lower, 'index') && !str_contains($lower, 'eq_ref') => self::YELLOW,
                str_contains($lower, 'eq_ref') || str_contains($lower, 'ref') || str_contains($lower, 'const') => self::GREEN,
                default => self::GRAY,
            },
            'key' => $lower === '' || $lower === 'null' ? self::RED : self::GREEN,
            'rows' => (is_numeric($value) && (int) $value > 10000) ? self::RED : ((is_numeric($value) && (int) $value > 1000) ? self::YELLOW : self::GREEN),
            'extra' => (str_contains($lower, 'using temporary') || str_contains($lower, 'using filesort')) ? self::RED : self::GRAY,
            default => self::GRAY,
        };
    }
}
