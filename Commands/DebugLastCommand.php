<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class DebugLastCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $traceDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';

        if (!is_dir($traceDir)) {
            $this->write('No traces found. Enable APP_DEBUG=true to capture traces.');
            return 1;
        }

        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if ($files === []) {
            $this->write('No traces found.');
            return 1;
        }

        rsort($files);
        $latest = $files[0];
        $data = json_decode((string) file_get_contents($latest), true);
        if (!is_array($data)) {
            $this->write('Invalid trace file.');
            return 1;
        }

        $traceId = basename($latest, '.json');

        $this->write('');
        $this->write('  ' . str_repeat('=', 56));
        $this->write('  ⚡ LAST REQUEST — ' . ($data['method'] ?? '?') . ' ' . ($data['path'] ?? '?'));
        $this->write('  ' . str_repeat('=', 56));
        $this->write('  Trace ID: ' . $traceId);
        $this->write('  Time:     ' . ($data['timestamp'] ?? '?'));
        $this->write('  Status:   ' . ($data['status'] ?? '?') . ' (' . ($data['time_ms'] ?? '?') . 'ms)');
        $this->write('  IP:       ' . ($data['ip'] ?? '?'));
        $this->write('  Host:     ' . ($data['host'] ?? '?'));
        $this->write('  Memory:   ' . ($data['memory_mb'] ?? '?') . 'MB');

        $headers = $data['request_headers'] ?? [];
        if ($headers !== []) {
            $this->write('');
            $this->write('  --- Request Headers ---');
            foreach ($headers as $key => $value) {
                $k = strtolower((string) $key);
                if ($k === 'authorization' || $k === 'cookie') {
                    $this->write('  ' . $key . ': [REDACTED]');
                } else {
                    $this->write('  ' . $key . ': ' . $value);
                }
            }
        }

        $requestBody = $data['request_body'] ?? '';
        if ($requestBody !== '' && $requestBody !== '[]' && $requestBody !== '{}') {
            $this->write('');
            $this->write('  --- Request Body ---');
            $formatted = $this->prettyJson($requestBody);
            foreach (explode("\n", $formatted) as $line) {
                $this->write('  ' . $line);
            }
        }

        $responseBody = $data['response_body'] ?? '';
        if ($responseBody !== '' && $responseBody !== '[]' && $responseBody !== '{}') {
            $this->write('');
            $this->write('  --- Response Body ---');
            $formatted = $this->prettyJson($responseBody);
            foreach (explode("\n", $formatted) as $line) {
                $this->write('  ' . $line);
            }
        }

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
        $this->write('  Replay:  php siro log:replay ' . $traceId);
        $this->write('  Export:  php siro log:export ' . $traceId . ' --postman');
        $this->write('  ' . str_repeat('=', 56));

        return 0;
    }

    private function prettyJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $json;
    }
}
