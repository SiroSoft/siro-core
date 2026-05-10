<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogTraceCommand
{
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
            } elseif ($arg === '--full') {
                $full = true;
            } elseif ($arg !== '') {
                $traceId = $arg;
            }
        }

        if ($traceId !== '') {
            return $this->showTrace($traceId, $full);
        }

        return $this->listTraces($status, $method, $slow, $limit);
    }

    private function showTrace(string $traceId, bool $full = false): int
    {
        $traceFile = $this->basePath
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'traces'
            . DIRECTORY_SEPARATOR . $traceId . '.json';

        if (!is_file($traceFile)) {
            $this->write('Trace not found: ' . $traceId);
            $this->write('Looked in: ' . $traceFile);
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
        $this->write('  Time:    ' . ($data['timestamp'] ?? '?'));
        $this->write('  Method:  ' . ($data['method'] ?? '?') . ' ' . ($data['path'] ?? '?'));
        $this->write('  Status:  ' . ($data['status'] ?? '?') . ' (' . ($data['time_ms'] ?? '?') . 'ms)');
        $this->write('  IP:      ' . ($data['ip'] ?? '?'));
        $this->write('  Host:    ' . ($data['host'] ?? '?'));

        if (isset($data['memory_mb'])) {
            $this->write('  Memory:  ' . $data['memory_mb'] . 'MB');
        }

        $requestBody = $data['request_body'] ?? '';
        if ($requestBody !== '' && $requestBody !== '[]' && $requestBody !== '{}') {
            $this->write('');
            $this->write('  Request Body:');
            $displayBody = $full ? $requestBody : mb_substr($requestBody, 0, 500);
            if ($full) {
                $formatted = json_decode($displayBody, true);
                if (is_array($formatted)) {
                    foreach (explode("\n", (string) json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) as $line) {
                        $this->write('    ' . $line);
                    }
                } else {
                    $this->write('    ' . $displayBody);
                }
            } else {
                $this->write('    ' . $displayBody);
            }
        }

        $responseBody = $data['response_body'] ?? '';
        if ($responseBody !== '' && $responseBody !== '[]' && $responseBody !== '{}') {
            $this->write('');
            $this->write('  Response Body:');
            $displayBody = $full ? $responseBody : mb_substr($responseBody, 0, 500);
            if ($full) {
                $formatted = json_decode($displayBody, true);
                if (is_array($formatted)) {
                    foreach (explode("\n", (string) json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) as $line) {
                        $this->write('    ' . $line);
                    }
                } else {
                    $this->write('    ' . $displayBody);
                }
            } else {
                $this->write('    ' . $displayBody);
            }
        }

        $authHeader = $data['auth_header'] ?? '';
        if ($authHeader !== '') {
            $this->write('');
            $this->write('  Auth: ' . mb_substr($authHeader, 0, 50) . '...');
        }

        if (isset($data['request_headers']) && is_array($data['request_headers']) && $full) {
            $this->write('');
            $this->write('  Request Headers:');
            foreach ($data['request_headers'] as $key => $value) {
                $k = strtolower((string) $key);
                if ($k === 'authorization' || $k === 'cookie') {
                    $this->write('    ' . $key . ': [REDACTED]');
                } else {
                    $this->write('    ' . $key . ': ' . $value);
                }
            }
        }

        if (isset($data['queries']) && is_array($data['queries']) && $data['queries'] !== []) {
            $this->write('');
            $this->write('  SQL Queries (' . count($data['queries']) . '):');
            $totalTime = 0;
            foreach ($data['queries'] as $i => $q) {
                $totalTime += (float) ($q['time_ms'] ?? 0);
                $sql = $q['sql'] ?? '?';
                if (!$full && mb_strlen($sql) > 120) {
                    $sql = mb_substr($sql, 0, 120) . '...';
                }
                $this->write(sprintf(
                    '    %d. %s [%d rows, %.2fms]',
                    $i + 1, $sql, $q['rows'] ?? 0, $q['time_ms'] ?? 0
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

    private function listTraces(?int $status, ?string $method, bool $slow, int $limit): int
    {
        $traceDir = $this->basePath
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'traces';

        if (!is_dir($traceDir)) {
            $this->write('No traces found.');
            $this->write('Traces are created automatically for each request when APP_DEBUG=true.');
            return 0;
        }

        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
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

            if ($status !== null && ((int) ($data['status'] ?? 0)) !== $status) {
                continue;
            }
            if ($method !== null && strtoupper($data['method'] ?? '') !== $method) {
                continue;
            }
            if ($slow && (float) ($data['time_ms'] ?? 0) < 100) {
                continue;
            }

            $id = basename($file, '.json');
            $m = $data['method'] ?? '?';
            $s = (string) ($data['status'] ?? '?');
            $t = ($data['time_ms'] ?? '?') . 'ms';
            $p = $data['path'] ?? '?';

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
