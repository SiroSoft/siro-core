<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class ApiWhyCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    private const SLOW_SQL_MS = 100;
    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const BOLD = "\033[1m";
    private const GRAY = "\033[90m";

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $method = strtoupper(trim((string) ($args[0] ?? '')));
        $path = trim((string) ($args[1] ?? ''));

        if ($method === '' || $path === '') {
            $this->write('  ' . self::YELLOW . 'Usage: php siro api:why <METHOD> <path>' . self::RESET);
            $this->write('  ' . self::GRAY . '  php siro api:why POST /api/orders' . self::RESET);
            $this->write('  ' . self::GRAY . '  php siro api:why GET /api/products/10' . self::RESET);
            return 1;
        }

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            $this->write('  ' . self::RED . 'Invalid method: ' . $method . self::RESET);
            return 1;
        }

        $normalizedPath = '/' . ltrim($path, '/');

        $tracesDir = $this->getTracesDir($this->basePath);
        $files = $this->findTraceFiles($tracesDir);
        if ($files === []) {
            $this->write('  ' . self::YELLOW . 'No traces found. Enable APP_DEBUG=true to capture traces.' . self::RESET);
            return 1;
        }

        rsort($files);

        $matchedTrace = null;
        $matchedData = null;
        $matchedFile = null;

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $traceMethod = strtoupper((string) ($data['method'] ?? ''));
            $tracePath = (string) ($data['path'] ?? '');

            if ($traceMethod === $method && $tracePath === $normalizedPath) {
                $matchedTrace = basename($file, '.json');
                $matchedData = $data;
                $matchedFile = $file;
                break;
            }
        }

        if ($matchedData === null) {
            $this->write('  ' . self::YELLOW . 'No trace found for ' . $method . ' ' . $normalizedPath . self::RESET);
            $this->write('  ' . self::GRAY . 'Available methods: try php siro log:trace --method=' . $method . self::RESET);
            return 1;
        }

        /** @var array<string, mixed> $matchedData */
        $this->displayTrace($method, $normalizedPath, $matchedData, $matchedTrace ?? '');

        return 0;
    }

    /** @param array<string, mixed> $data */
    private function displayTrace(string $method, string $path, array $data, string $traceId): void
    {
        $statusVal = $data['status'] ?? 0;
        $status = is_numeric($statusVal) ? (int) $statusVal : 0;
        $timeMs = is_numeric($data['time_ms'] ?? null) ? (float) $data['time_ms'] : 0.0;
        $exceptionRaw = $data['exception'] ?? $data['error'] ?? null;

        // ── Header ──
        $this->write('');
        $this->write('  ' . self::BOLD . 'Request' . self::RESET);
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);
        $this->write('  Route:    ' . self::CYAN . $method . ' ' . $path . self::RESET);

        $statusIcon = $status >= 500 ? '✗' : ($status >= 400 ? '!' : ($status >= 200 && $status < 300 ? '✓' : '?'));
        $statusColor = $status >= 500 ? self::RED : ($status >= 400 ? self::YELLOW : self::GREEN);
        $durationColor = $timeMs > 500 ? self::RED : ($timeMs > 100 ? self::YELLOW : self::GREEN);
        $this->write("  Status:   " . $statusColor . $statusIcon . " " . $status . self::RESET);
        $this->write("  Duration: " . $durationColor . sprintf('%.0fms', $timeMs) . self::RESET);
        if ($traceId !== '') {
            $this->write('  Trace ID: ' . self::CYAN . $traceId . self::RESET);
        }
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);

        // ── Middleware Pipeline ──
        $middleware = $data['middleware'] ?? null;
        if (is_array($middleware) && $middleware !== []) {
            $this->write('  ' . self::BOLD . 'Middleware Pipeline' . self::RESET);
            $mwCount = count($middleware);
            foreach ($middleware as $idx => $mw) {
                if (!is_array($mw)) continue;
                $mwName = $this->safeStr(is_string($mw['name'] ?? null) ? $mw['name'] : '?');
                $mwPassed = (bool) ($mw['passed'] ?? true);
                $mwTime = is_numeric($mw['time_ms'] ?? null) ? (float) $mw['time_ms'] : 0.0;

                $connector = ($idx < $mwCount - 1) ? '├' : '└';
                $icon = $mwPassed ? self::GREEN . '✓' : self::RED . '✗';
                $timeStr = sprintf('%.1fms', $mwTime);
                $slowMark = $mwTime > self::SLOW_SQL_MS ? ' ' . self::YELLOW . '⚠ slow' . self::RESET : '';
                $lineColor = !$mwPassed ? self::RED : self::GRAY;
                $this->write("    " . $lineColor . $connector . " " . $icon . self::RESET . " " . $mwName . " " . self::GRAY . $timeStr . self::RESET . $slowMark);
            }
            $this->write('');
        }

        // ── SQL Queries ──
        $queries = $data['queries'] ?? [];
        if (is_array($queries) && $queries !== []) {
            $this->write('  ' . self::BOLD . 'SQL Queries' . self::RESET);
            $totalSqlTime = 0.0;
            foreach ($queries as $idx => $q) {
                if (!is_array($q)) continue;
                $qTime = is_numeric($q['time_ms'] ?? null) ? (float) $q['time_ms'] : 0.0;
                $totalSqlTime += $qTime;
                $qSql = $this->safeStr(is_string($q['sql'] ?? null) ? $q['sql'] : '?');
                $qAction = strtoupper(explode(' ', trim($qSql))[0] ?? '');
                // Show only first 80 chars of SQL body (after action word)
                $qBody = trim(substr($qSql, strlen($qAction)));
                $qDisplay = strlen($qBody) > 80 ? substr($qBody, 0, 77) . '...' : $qBody;
                $qColor = $qTime > self::SLOW_SQL_MS ? self::YELLOW : self::GRAY;
                $qIcon = $qTime > self::SLOW_SQL_MS ? '⚠' : '▸';
                $slowLabel = $qTime > self::SLOW_SQL_MS ? ' ' . self::YELLOW . '⚠ slow' . self::RESET : '';

                $connector = ($idx < count($queries) - 1) ? '├' : '└';
                $this->write("    " . $qColor . $connector . " " . $qIcon . " " . $qAction . self::RESET . $qDisplay . " " . self::GRAY . sprintf('%.0fms', $qTime) . self::RESET . $slowLabel);
            }
            $this->write('    ' . self::GRAY . '  Total SQL: ' . sprintf('%.0fms', $totalSqlTime) . self::RESET);
            $this->write('');
        }

        // ── N+1 Detection ──
        if (class_exists(\Siro\Core\Model::class)) {
            $accessCount = \Siro\Core\Model::getRelationAccessCount();
            if ($accessCount !== []) {
                foreach ($accessCount as $key => $count) {
                    if ($count >= 2) {
                        $parts = explode('::', $key);
                        $relName = $parts[1] ?? $key;
                        $this->write('  ' . self::YELLOW . '⚠ N+1' . self::RESET . ' ' . $key . ' accessed ' . $count . 'x');
                        $this->write('    Fix: ' . self::CYAN . "with('" . $relName . "')" . self::RESET);
                    }
                }
            }
        }

        // ── Exception ──
        $exceptionMsg = '';
        $exceptionClass = '';
        if (is_string($exceptionRaw) && $exceptionRaw !== '') {
            $exceptionMsg = $exceptionRaw;
        } elseif (is_array($exceptionRaw)) {
            $exceptionClass = is_string($exceptionRaw['class'] ?? null) ? $exceptionRaw['class'] : '';
            $exceptionMsg = is_string($exceptionRaw['message'] ?? null) ? $exceptionRaw['message'] : '';
        }

        if ($exceptionMsg !== '') {
            $displayEx = $exceptionClass !== '' ? $exceptionClass . ': ' . $exceptionMsg : $exceptionMsg;
            $this->write('  ' . self::BOLD . self::RED . 'Exception' . self::RESET);
            $this->write('    ' . $displayEx);
            $this->write('');

            // Possible cause
            $cause = $this->guessCause($exceptionClass, $exceptionMsg, $status);
            if ($cause !== []) {
                $this->write('  ' . self::BOLD . 'Possible Cause' . self::RESET);
                foreach ($cause as $c) {
                    $this->write('    ' . self::YELLOW . '•' . self::RESET . ' ' . $c);
                }
                $this->write('');
            }

            // Suggested fix
            $fix = $this->guessFix($exceptionClass, $exceptionMsg, $status, $traceId);
            if ($fix !== []) {
                $this->write('  ' . self::BOLD . 'Suggested Fix' . self::RESET);
                foreach ($fix as $f) {
                    $this->write('    ' . self::GREEN . '▸' . self::RESET . ' ' . $f);
                }
                $this->write('');
            }
        }

        // ── Response Source ──
        $source = $this->findResponseSource($data);
        if ($source !== '') {
            $this->write('  ' . self::BOLD . 'Response Source' . self::RESET);
            $this->write('    ' . self::CYAN . '└ ' . $source . self::RESET);
            $this->write('');
        }

        // ── Replay shortcuts ──
        $isWriteMethod = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $forceFlag = $isWriteMethod ? ' --force' : '';
        $this->write('  ' . self::BOLD . 'Replay' . self::RESET);
        $this->write('    ' . self::CYAN . '[r]' . self::RESET . '  php siro replay ' . $traceId . $forceFlag);
        $this->write('    ' . self::CYAN . '[e]' . self::RESET . '  php siro replay ' . $traceId . ' --edit');
        $this->write('    ' . self::CYAN . '[d]' . self::RESET . '  php siro replay ' . $traceId . ' --diff');
        $this->write('    ' . self::CYAN . '[t]' . self::RESET . '  php siro make:test --from-trace=' . $traceId);
        $this->write('');

        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);
    }

    private function safeStr(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (is_numeric($value)) return (string) $value;
        if ($value === null) return '';
        if (is_bool($value)) return $value ? 'true' : 'false';
        return '';
    }

    /** @param array<string, mixed> $data */
    private function findResponseSource(array $data): string
    {
        // Try to infer the source from the trace data
        // This is a best-effort: we check if there's a controller hint
        $responseBody = $data['response_body'] ?? '';
        if (is_string($responseBody) && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded) && isset($decoded['_source'])) {
                return is_string($decoded['_source']) ? $decoded['_source'] : '';
            }
        }
        return '';
    }

    /** @return list<string> */
    private function guessCause(string $class, string $message, int $status): array
    {
        $lowerMsg = strtolower($message);

        if (str_contains($lowerMsg, 'deadlock')) {
            return ['Concurrent transaction conflict', 'Missing retry logic for deadlock scenarios'];
        }
        if (str_contains($lowerMsg, 'timeout')) {
            return ['Slow query exceeding timeout', 'Database connection timeout'];
        }
        if (str_contains($lowerMsg, 'not found')) {
            return ['Missing record in database', 'Invalid foreign key reference'];
        }
        if (str_contains($lowerMsg, 'duplicate') || str_contains($lowerMsg, 'unique')) {
            return ['Duplicate entry violates unique constraint'];
        }
        if ($status === 401) {
            return ['Missing or expired authentication token'];
        }
        if ($status === 403) {
            return ['Authenticated user lacks required role'];
        }
        if ($status === 422) {
            return ['Request body fails validation rules'];
        }
        if ($status >= 500) {
            return ['Unhandled exception in controller or service'];
        }

        return [];
    }

    /** @return list<string> */
    private function guessFix(string $class, string $message, int $status, string $traceId): array
    {
        $lowerMsg = strtolower($message);
        $replayForce = 'php siro replay ' . $traceId . ' --force';
        $replayEdit = 'php siro replay ' . $traceId . ' --edit';

        if (str_contains($lowerMsg, 'deadlock')) {
            return ['Wrap transaction in retry loop (max 3 attempts)', 'Reduce transaction scope', $replayEdit];
        }
        if (str_contains($lowerMsg, 'timeout')) {
            return ['Add missing database indexes', 'Increase timeout in config/database.php'];
        }
        if ($status === 401) {
            return ['Include Authorization: Bearer <token> header', 'Login first via POST /api/auth/login'];
        }
        if ($status === 422) {
            return [$replayEdit . ' to fix request body', 'Check validation rules in FormRequest'];
        }
        if ($status >= 500) {
            return [$replayForce . ' to reproduce', $replayEdit . ' to test fixes'];
        }

        return [];
    }

    private function getTracesDir(string $basePath): string
    {
        $loggerDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        if (is_dir($loggerDir)) {
            return $loggerDir;
        }
        return $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
    }

    /** @return list<string> */
    private function findTraceFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        return is_array($files) ? $files : [];
    }
}
