<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Show details of the last request trace.
 *
 * Displays method, path, status, timing, headers,
 * request/response body, SQL queries, and validation
 * errors with quick-fix suggestions.
 * Alias: php siro why
 *
 * @package Siro\Core\Commands
 */
final class DebugLastCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $traceDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';

        if (!is_dir($traceDir)) {
            $this->write('  ⚠ No traces found. Enable APP_DEBUG=true to capture traces.');
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
        $status = (int) ($data['status'] ?? 0);
        $method = $data['method'] ?? 'GET';
        $path = $data['path'] ?? '/';
        $timeMs = $data['time_ms'] ?? 0;
        $statusColor = $status >= 200 && $status < 300 ? 'green' : ($status >= 400 ? 'red' : 'yellow');
        $statusText = $status >= 200 && $status < 300 ? 'OK' : ($status === 422 ? 'Unprocessable Entity' : ($status === 401 ? 'Unauthorized' : ($status === 404 ? 'Not Found' : ($status === 500 ? 'Server Error' : 'Unknown'))));

        $this->write('');
        $this->write('  ' . str_repeat('=', 56));
        $this->write('  ⚡ LAST REQUEST');
        $this->write('  ' . str_repeat('=', 56));
        $this->write('  ' . $method . ' ' . $path);
        $this->write('  Status:   ' . $status . ' ' . $statusText . ' (' . round((float) $timeMs, 1) . 'ms)');
        $this->write('  Trace ID: ' . $traceId);
        $this->write('  ' . str_repeat('-', 56));
        $this->write('  💡 Replay: php siro log:replay ' . $traceId);
        $this->write('  ' . str_repeat('-', 56));
        $this->write('  Time:     ' . ($data['timestamp'] ?? '?'));
        $this->write('  IP:       ' . ($data['ip'] ?? '?'));
        $this->write('  Memory:   ' . ($data['memory_mb'] ?? '?') . 'MB');

        // Parse response body for errors
        $responseBody = $data['response_body'] ?? '';
        if ($responseBody !== '' && $responseBody !== '{}') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $errors = $decoded['errors'] ?? ($decoded['data']['errors'] ?? []);
                if ($errors !== [] && is_array($errors)) {
                    $this->write('');
                    $this->write('  ❌ Validation failed:');
                    $hasBodyKeys = false;
                    foreach ($errors as $field => $msgs) {
                        $fieldStr = is_array($msgs) ? implode(', ', $msgs) : (string) $msgs;
                        $this->write('    - ' . $field . ': ' . $fieldStr);
                        if ($method !== 'GET') $hasBodyKeys = true;
                    }
                    $this->write('');
                    if ($hasBodyKeys) {
                        $this->write('  💡 Fix now:');
                        foreach ($errors as $field => $msgs) {
                            $this->write('    php siro replay ' . $traceId . ' --set body.' . $field . '=1');
                        }
                    }
                    $this->write('  💡 Or edit request body:');
                    $this->write('    php siro log:replay ' . $traceId . ' --edit');
                }
            }
        }

        // Show auth status
        $headers = $data['request_headers'] ?? [];
        $hasAuth = false;
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                $hasAuth = true;
                break;
            }
        }
        if ($status === 401 && !$hasAuth) {
            $this->write('');
            $this->write('  🔐 Requires authentication: add --as=admin or login first');
        }

        // Show request body
        $requestBody = $data['request_body'] ?? '';
        if ($requestBody !== '' && $requestBody !== '[]' && $requestBody !== '{}') {
            $this->write('');
            $this->write('  --- Request Body ---');
            $formatted = $this->prettyJson($requestBody);
            foreach (explode("\n", $formatted) as $line) {
                $this->write('  ' . $line);
            }
        }

        // Show response body
        if ($responseBody !== '' && $responseBody !== '{}') {
            $this->write('');
            $this->write('  --- Response Body ---');
            $formatted = $this->prettyJson($responseBody);
            foreach (explode("\n", $formatted) as $line) {
                $this->write('  ' . $line);
            }
        }

        // SQL queries
        if (isset($data['queries']) && is_array($data['queries']) && $data['queries'] !== []) {
            $this->write('');
            $this->write('  --- SQL Queries (' . count($data['queries']) . ') ---');
            $totalTime = 0;
            foreach ($data['queries'] as $i => $q) {
                $totalTime += (float) ($q['time_ms'] ?? 0);
                $this->write(sprintf(
                    '  %d. %s [%d rows, %.2fms]',
                    $i + 1,
                    $q['sql'] ?? '?',
                    $q['rows'] ?? 0,
                    $q['time_ms'] ?? 0
                ));
            }
            $this->write(sprintf('  Total SQL time: %.2fms', $totalTime));
        }

        $this->write('');
        $this->write('  ' . str_repeat('-', 56));
        $this->write('  🔄 Replay:  php siro log:replay ' . $traceId);
        $this->write('  📤 Export:  php siro log:export ' . $traceId . ' --postman');
        $this->write('  🔧 Fix & auto-replay: edit code then: php siro fix');
        $this->write('  ' . str_repeat('=', 56));

        return 0;
    }

    private function prettyJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $json;
    }
}
