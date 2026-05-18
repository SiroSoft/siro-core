<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogTraceCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * View and search request traces.
 *
 * Displays detailed trace information including method, path,
 * timing, SQL queries, request/response body. Supports filtering
 * by status code, HTTP method, and slow requests.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $traceId = '';
        $status = null;
        $method = null;
        $slow = false;
        $limit = 10;
        $full = false;
        $days = 0;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--status=')) {
                $status = (int) substr($arg, 9);
            } elseif (str_starts_with($arg, '--method=')) {
                $method = strtoupper(substr($arg, 9));
            } elseif ($arg === '--slow') {
                $slow = true;
                $limit = 50;
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 7));
            } elseif (str_starts_with($arg, '--days=')) {
                $days = max(1, (int) substr($arg, 7));
            } elseif ($arg === '--full') {
                $full = true;
            } elseif ($arg !== '') {
                $traceId = $arg;
            }
        }

        if ($traceId !== '') {
            return $this->showTrace($traceId, $full);
        }

        return $this->listTraces($status, $method, $slow, $limit, $days);
    }

    private function showTrace(string $traceId, bool $full = false): int
    {
        $tracesDir = $this->getTracesDir($this->basePath);
        $traceFile = $this->findTraceById($tracesDir, $traceId);

        if ($traceFile === null) {
            $this->write('Trace not found: ' . $traceId);
            $this->write('Looked in: ' . $tracesDir);
            return 1;
        }

        $data = json_decode((string) file_get_contents($traceFile), true);
        if (!is_array($data)) {
            $this->write('Invalid trace file.');
            return 1;
        }

        $this->write(str_repeat('=', 56));
        $this->write('  Trace: ' . $traceId);
        $this->write(str_repeat('-', 56));
        $this->write('  Time:    ' . $this->safeStr($data['timestamp'] ?? '?'));
        $this->write('  Method:  ' . $this->safeStr($data['method'] ?? '?') . ' ' . $this->safeStr($data['path'] ?? '?'));
        $this->write('  Status:  ' . $this->safeStr($data['status'] ?? '?') . ' (' . $this->safeStr($data['time_ms'] ?? '?') . 'ms)');
        $this->write('  IP:      ' . $this->safeStr($data['ip'] ?? '?'));
        $this->write('  Host:    ' . $this->safeStr($data['host'] ?? '?'));

        if (isset($data['memory_mb'])) {
            $this->write('  Memory:  ' . $this->safeStr($data['memory_mb']) . 'MB');
        }

        $requestBody = $this->safeStr($data['request_body'] ?? '');
        if ($requestBody !== '' && $requestBody !== '[]' && $requestBody !== '{}') {
            $this->write('');
            $this->write('  Request Body:');
            $displayBody = $full ? $requestBody : mb_substr($requestBody, 0, 500);
            if ($full) {
                $formatted = json_decode($displayBody, true);
                if (is_array($formatted)) {
                    foreach (explode("\n", $this->safeStr(json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) as $line) {
                        $this->write('    ' . $line);
                    }
                } else {
                    $this->write('    ' . $displayBody);
                }
            } else {
                $this->write('    ' . $displayBody);
            }
        }

        $responseBody = $this->safeStr($data['response_body'] ?? '');
        if ($responseBody !== '' && $responseBody !== '[]' && $responseBody !== '{}') {
            $this->write('');
            $this->write('  Response Body:');
            $displayBody = $full ? $responseBody : mb_substr($responseBody, 0, 500);
            if ($full) {
                $formatted = json_decode($displayBody, true);
                if (is_array($formatted)) {
                    foreach (explode("\n", $this->safeStr(json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) as $line) {
                        $this->write('    ' . $line);
                    }
                } else {
                    $this->write('    ' . $displayBody);
                }
            } else {
                $this->write('    ' . $displayBody);
            }
        }

        $authHeader = $this->safeStr($data['auth_header'] ?? '');
        if ($authHeader !== '') {
            $this->write('');
            $this->write('  Auth: ' . mb_substr($authHeader, 0, 50) . '...');
        }

        if (isset($data['request_headers']) && is_array($data['request_headers']) && $full) {
            $this->write('');
            $this->write('  Request Headers:');
            foreach ($data['request_headers'] as $key => $value) {
                $k = strtolower($this->safeStr($key));
                if ($k === 'authorization' || $k === 'cookie') {
                    $this->write('    ' . $this->safeStr($key) . ': [REDACTED]');
                } else {
                    $this->write('    ' . $this->safeStr($key) . ': ' . $this->safeStr($value));
                }
            }
        }

        if (isset($data['queries']) && is_array($data['queries']) && $data['queries'] !== []) {
            $this->write('');
            $this->write('  SQL Queries (' . count($data['queries']) . '):');
            $totalTime = 0;
            foreach ($data['queries'] as $i => $q) {
                /** @var array<string, mixed> $q */
                $queryTime = $q['time_ms'] ?? 0;
                $totalTime += is_numeric($queryTime) ? (float) $queryTime : 0;
                $sql = $this->safeStr($q['sql'] ?? '?');
                if (!$full && mb_strlen($sql) > 120) {
                    $sql = mb_substr($sql, 0, 120) . '...';
                }
                $rowsCount = $q['rows'] ?? 0;
                $queryMs = $q['time_ms'] ?? 0;
                $this->write(sprintf(
                    '    %d. %s [%d rows, %.2fms]',
                    $i + 1, $sql,
                    is_numeric($rowsCount) ? (int) $rowsCount : 0,
                    is_numeric($queryMs) ? (float) $queryMs : 0
                ));
            }
            $this->write(sprintf('    Total SQL time: %.2fms', $totalTime));
        }

        $this->write('');
        if ($full) {
            $this->write('  Debug: php siro debug:last');
        }
        $this->write('  Replay: php siro log:replay ' . $traceId);
        $this->write('  Export: php siro log:export ' . $traceId . ' --postman');
        $this->write(str_repeat('=', 56));

        return 0;
    }

    private function listTraces(?int $status, ?string $method, bool $slow, int $limit, int $days = 0): int
    {
        $tracesDir = $this->getTracesDir($this->basePath);

        $files = $this->findTraceFiles($tracesDir);

        // Filter by age if --days specified
        if ($days > 0) {
            $cutoff = time() - ($days * 86400);
            $files = array_values(array_filter($files, fn(string $f): bool => filemtime($f) >= $cutoff));
        }

        rsort($files);

        $count = 0;
        $this->write(str_pad('Trace ID', 20) . ' ' . str_pad('Method', 8) . ' ' . str_pad('Status', 7) . ' ' . str_pad('Time', 8) . ' Path');
        $this->write(str_repeat('-', 70));

        foreach ($files as $file) {
            if ($count >= $limit) {
                break;
            }

            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $dataStatus = $data['status'] ?? 0;
            $dataMethod = $this->safeStr($data['method'] ?? '');
            $dataTimeMs = $data['time_ms'] ?? 0;
            if ($status !== null && (is_numeric($dataStatus) ? (int) $dataStatus : 0) !== $status) {
                continue;
            }
            if ($method !== null && strtoupper($dataMethod) !== $method) {
                continue;
            }
            if ($slow && (is_numeric($dataTimeMs) ? (float) $dataTimeMs : 0) < 100) {
                continue;
            }

            $id = basename($file, '.json');
            $m = $this->safeStr($data['method'] ?? '?');
            $s = $this->safeStr($data['status'] ?? '?');
            $t = $this->safeStr($data['time_ms'] ?? '?') . 'ms';
            $p = $this->safeStr($data['path'] ?? '?');

            $this->write(str_pad($id, 20) . ' ' . str_pad($m, 8) . ' ' . str_pad($s, 7) . ' ' . str_pad($t, 8) . ' ' . $p);
            $count++;
        }

        if ($count === 0) {
            $this->write('No traces match the given filters.');
        }

        $this->write('');
        $this->write('Total: ' . $count . ' traces (use --limit=N to show more)');
        return 0;
    }
}
