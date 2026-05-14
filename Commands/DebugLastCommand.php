<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Show details of the last request trace.
 *
 * Displays method, path, status, timing, trace ID,
 * SQL queries with slow warnings, middleware status,
 * exception info, and replay commands.
 *
 * Alias: php siro why
 *
 * @package Siro\Core\Commands
 */
final class DebugLastCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    private const SLOW_SQL_MS = 100;

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $traceDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';

        if (!is_dir($traceDir)) {
            $this->write('  No traces found. Enable APP_DEBUG=true to capture traces.');
            return 1;
        }

        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if ($files === []) {
            $this->write('  No traces found.');
            return 1;
        }

        rsort($files);
        $latest = $files[0];
        $data = json_decode((string) file_get_contents($latest), true);
        if (!is_array($data)) {
            $this->write('  Invalid trace file.');
            return 1;
        }

        $traceId = basename($latest, '.json');
        $method = $this->safeStr($data['method'] ?? 'GET');
        $path = $this->safeStr($data['path'] ?? '/');
        $statusVal = $data['status'] ?? 0;
        $status = is_numeric($statusVal) ? (int) $statusVal : 0;
        $timeMs = is_numeric($data['time_ms'] ?? null) ? (float) $data['time_ms'] : 0.0;

        $this->write('');
        $this->write('  Last Request Summary');
        $this->write('  ' . str_repeat('─', 50));

        // Route
        $this->write('  Route:    ' . $method . ' ' . $path);

        // Status with color hint
        $statusColor = $status >= 500 ? '❌' : ($status >= 400 ? '⚠' : ($status >= 200 && $status < 300 ? '✅' : ''));
        $this->write("  Status:   $statusColor $status ($timeMs" . "ms)");

        // Trace ID
        $this->write('  Trace ID: ' . $traceId);
        $this->write('  ' . str_repeat('─', 50));

        // SQL Queries
        $queries = $data['queries'] ?? [];
        if (is_array($queries) && $queries !== []) {
            $this->write('  SQL Queries:');
            $totalSqlTime = 0.0;
            foreach ($queries as $q) {
                if (!is_array($q)) continue;
                $qTime = is_numeric($q['time_ms'] ?? null) ? (float) $q['time_ms'] : 0.0;
                $totalSqlTime += $qTime;
                $qSql = $this->safeStr(is_string($q['sql'] ?? null) ? $q['sql'] : '?');
                $qRows = is_numeric($q['rows'] ?? null) ? (int) $q['rows'] : 0;
                $slow = $qTime > self::SLOW_SQL_MS ? ' ⚠ SLOW' : '';
                $this->write(sprintf('    - %s (%s%.1fms)%s', $qSql, $qRows > 0 ? $qRows . ' rows, ' : '', $qTime, $slow));
            }
            $this->write(sprintf('    Total SQL: %.1fms', $totalSqlTime));
            $this->write('');
        }

        // Middleware status (if trace captures it)
        $middleware = $data['middleware'] ?? null;
        if (is_array($middleware)) {
            $this->write('  Middleware:');
            foreach ($middleware as $mw) {
                if (!is_array($mw)) continue;
                $name = $this->safeStr(is_string($mw['name'] ?? null) ? $mw['name'] : '?');
                $passed = (bool) ($mw['passed'] ?? true);
                $icon = $passed ? '✅' : '✗';
                $this->write("    $icon $name");
            }
            $this->write('');
        }

        // Exception
        $exception = $data['exception'] ?? $data['error'] ?? null;
        if (is_string($exception) && $exception !== '') {
            $this->write('  Exception:');
            $this->write('    ' . $exception);
            $this->write('');
        } elseif (is_array($exception)) {
            $this->write('  Exception:');
            $exClass = is_string($exception['class'] ?? null) ? $exception['class'] : 'Error';
            $exMsg = is_string($exception['message'] ?? null) ? $exception['message'] : '';
            $this->write("    $exClass: $exMsg");
            $this->write('');
        }

        // Response body for validation errors
        $responseBody = $this->safeStr($data['response_body'] ?? '');
        if ($responseBody !== '' && $responseBody !== '{}') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $errors = $decoded['errors'] ?? [];
                if ($errors === [] && isset($decoded['data']) && is_array($decoded['data'])) {
                    $errors = $decoded['data']['errors'] ?? [];
                }
                if (is_array($errors) && $errors !== []) {
                    $this->write('  Validation failed:');
                    foreach ($errors as $field => $msgs) {
                        $fieldStr = is_array($msgs) ? implode(', ', array_map(fn($v): string => $this->safeStr($v), (array) $msgs)) : $this->safeStr($msgs);
                        $this->write('    - ' . $this->safeStr((string) $field) . ': ' . $fieldStr);
                    }
                    $this->write('');
                    $this->write('  Fix: php siro log:replay ' . $traceId . ' --edit');
                    $this->write('');
                }
            }
        }

        // Auth hint
        $headers = $data['request_headers'] ?? [];
        $hasAuth = false;
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === 'authorization') {
                    $hasAuth = true;
                    break;
                }
            }
        }
        if ($status === 401 && !$hasAuth) {
            $this->write('  Requires authentication: add --as=admin or login first');
            $this->write('');
        }

        // Replay commands
        $this->write('  Replay:');
        $this->write('    php siro replay ' . $traceId);
        $this->write('    php siro replay ' . $traceId . ' --dry-run');
        $this->write('    php siro replay ' . $traceId . ' --edit');
        $this->write('    php siro log:export ' . $traceId . ' --postman');
        $this->write('    php siro log:replay ' . $traceId . ' --diff');
        $this->write('');

        $this->write('  ' . str_repeat('─', 50));
        return 0;
    }
}
