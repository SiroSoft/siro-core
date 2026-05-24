<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Show details of the last request trace.
 *
 * Signature command of Siro:
 *   php siro why
 *
 * Displays route, status, timing, trace ID,
 * SQL queries with slow warnings, middleware timeline,
 * exception with possible cause + suggested fix,
 * and replay shortcuts.
 *
 * @package Siro\Core\Commands
 */
final class DebugLastCommand implements \Siro\Core\Commands\CommandInterface {
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
        $tracesDir = $this->getTracesDir($this->basePath);
        $files = $this->findTraceFiles($tracesDir);
        if ($files === []) {
            $this->write('  ' . self::YELLOW . 'No traces found. Enable APP_DEBUG=true to capture traces.' . self::RESET);
            return 1;
        }

        rsort($files);
        $latest = $files[0];
        $data = json_decode((string) file_get_contents($latest), true);
        if (!is_array($data)) {
            $this->write('  ' . self::RED . 'Invalid trace file.' . self::RESET);
            return 1;
        }

        $traceId = basename($latest, '.json');
        $method = $this->safeStr($data['method'] ?? 'GET');
        $path = $this->safeStr($data['path'] ?? '/');
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
        $this->write('  Trace ID: ' . self::CYAN . $traceId . self::RESET);
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
                $qBody = trim(substr($qSql, strlen($qAction)));
                $qDisplay = strlen($qBody) > 80 ? substr($qBody, 0, 77) . '...' : $qBody;
                $qColor = $qTime > self::SLOW_SQL_MS ? self::YELLOW : self::GRAY;
                $qIcon = $qTime > self::SLOW_SQL_MS ? '⚠' : '▸';
                $slowLabel = $qTime > self::SLOW_SQL_MS ? ' ' . self::YELLOW . '⚠ slow' . self::RESET : '';

                $connector = ($idx < count($queries) - 1) ? '├' : '└';
                $this->write("    " . $qColor . $connector . " " . $qIcon . " " . $qAction . self::RESET . " " . $qDisplay . " " . self::GRAY . sprintf('%.0fms', $qTime) . self::RESET . $slowLabel);
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

        // ── Exception + Cause + Fix ──
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
            $this->write('    └ ' . $displayEx);
            $this->write('');

            $cause = $this->guessCause($exceptionClass, $exceptionMsg, $status);
            if ($cause !== []) {
                $this->write('  ' . self::BOLD . 'Possible Cause' . self::RESET);
                foreach ($cause as $c) {
                    $this->write('    ' . self::YELLOW . '•' . self::RESET . ' ' . $c);
                }
                $this->write('');
            }

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
        $source = $data['response_source'] ?? '';
        if ($source === '' || !is_string($source)) {
            $source = $this->findResponseSource($data);
        }
        if ($source !== '') {
            $this->write('  ' . self::BOLD . 'Response Source' . self::RESET);
            $this->write('    └ ' . self::CYAN . $source . self::RESET);
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
        return 0;
    }

    /** @return list<string> */
    private function guessCause(string $class, string $message, int $status): array
    {
        $lowerMsg = strtolower($message);
        $lowerClass = strtolower($class);

        if (str_contains($lowerMsg, 'deadlock') || str_contains($lowerMsg, 'lock')) {
            return [
                'Concurrent transaction conflict',
                'Missing retry logic for deadlock scenarios',
                'Long-running transaction holding locks',
            ];
        }
        if (str_contains($lowerMsg, 'timeout') || str_contains($lowerMsg, 'timed out')) {
            return [
                'Database connection timeout',
                'Slow query exceeding default timeout',
                'Network congestion or DB overload',
            ];
        }
        if (str_contains($lowerMsg, 'not found') || str_contains($lowerMsg, 'not exist')) {
            return [
                'Missing record in database',
                'Invalid foreign key reference',
            ];
        }
        if (str_contains($lowerMsg, 'duplicate') || str_contains($lowerMsg, 'unique')) {
            return [
                'Duplicate entry violates unique constraint',
                'Missing duplicate check before insert',
            ];
        }
        if ($status === 401 || str_contains($lowerMsg, 'auth') || str_contains($lowerMsg, 'unauthorized')) {
            return [
                'Missing or expired authentication token',
                'Insufficient permissions for this route',
            ];
        }
        if ($status === 403 || str_contains($lowerMsg, 'forbidden')) {
            return [
                'Authenticated user lacks required role',
                'Missing role middleware on this route',
            ];
        }
        if ($status === 422) {
            return [
                'Request body fails validation rules',
                'Missing or malformed required fields',
            ];
        }
        if ($status >= 500 && $status < 600) {
            return [
                'Unhandled exception in controller or service',
                'Check exception details above for root cause',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function guessFix(string $class, string $message, int $status, string $traceId = ''): array
    {
        $lowerMsg = strtolower($message);
        $lowerClass = strtolower($class);

        $replay = 'php siro replay ' . $traceId;
        $replayForce = $replay . ' --force';
        $replayEdit = $replay . ' --edit';

        if (str_contains($lowerMsg, 'deadlock') || str_contains($lowerMsg, 'lock')) {
            return [
                'Wrap transaction in retry loop (max 3 attempts)',
                'Reduce transaction scope — only lock what you need',
                'Add FOR UPDATE / SKIP LOCKED to SELECT queries',
                "$replayEdit to test fix",
            ];
        }
        if (str_contains($lowerMsg, 'timeout') || str_contains($lowerMsg, 'timed out')) {
            return [
                'Check slow query with SLOW LOG above',
                'Add missing database indexes',
                'Increase timeout in config/database.php',
            ];
        }
        if (str_contains($lowerMsg, 'not found') || str_contains($lowerMsg, 'not exist')) {
            return [
                'Verify the record exists before querying',
                'Check foreign key constraint integrity',
            ];
        }
        if (str_contains($lowerMsg, 'duplicate') || str_contains($lowerMsg, 'unique')) {
            return [
                'Add duplicate check before insert',
                'Use INSERT ... ON DUPLICATE KEY UPDATE',
            ];
        }
        if ($status === 401) {
            return [
                'php siro make:auth to generate auth endpoints',
                'Include Authorization: Bearer <token> header',
                'Login first: php siro t POST /api/auth/login --body={"email":"...","password":"..."}',
            ];
        }
        if ($status === 403) {
            return [
                'Check your role: user() in your controller',
                'Add route middleware: ->middleware(["auth", "role:admin"])',
            ];
        }
        if ($status === 422) {
            return [
                "$replayEdit to fix request body",
                'Check validation rules in FormRequest class',
            ];
        }
        if ($status >= 500) {
            return [
                "$replayForce to reproduce locally",
                "$replayEdit to test fixes",
                'Add error handling for the exception class above',
            ];
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function findResponseSource(array $data): string
    {
        $responseBody = $data['response_body'] ?? '';
        if (is_string($responseBody) && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded) && isset($decoded['_source'])) {
                return is_string($decoded['_source']) ? $decoded['_source'] : '';
            }
        }
        return '';
    }
}
