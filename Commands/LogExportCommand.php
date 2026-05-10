<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogExportCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $format = 'json';
        $output = '';
        $status = null;
        $method = null;
        $slow = false;
        $days = null;
        $traceId = '';

        foreach ($args as $arg) {
            $arg = trim($arg);
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } elseif (str_starts_with($arg, '--output=')) {
                $output = substr($arg, 9);
            } elseif (str_starts_with($arg, '--status=')) {
                $status = (int) substr($arg, 9);
            } elseif (str_starts_with($arg, '--method=')) {
                $method = strtoupper(substr($arg, 9));
            } elseif ($arg === '--slow') {
                $slow = true;
            } elseif (str_starts_with($arg, '--days=')) {
                $days = (int) substr($arg, 7);
            } elseif ($arg === '--postman' || $arg === '--curl') {
                $format = 'postman';
            } elseif ($arg !== '' && !str_starts_with($arg, '--')) {
                $traceId = $arg;
            }
        }

        if ($format === 'postman') {
            return $this->exportPostman($traceId);
        }

        $traceDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';

        if (!is_dir($traceDir)) {
            $this->write('No traces directory found.');
            return 1;
        }

        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        rsort($files);

        $cutoff = $days !== null ? time() - ($days * 86400) : 0;
        $traces = [];

        foreach ($files as $file) {
            if ($cutoff > 0 && filemtime($file) < $cutoff) {
                continue;
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

            $traces[] = $data;
        }

        if ($traces === []) {
            $this->write('No traces match the given filters.');
            return 0;
        }

        if ($format === 'json') {
            $content = (string) json_encode($traces, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } elseif ($format === 'csv') {
            $content = $this->toCsv($traces);
        } else {
            $this->write('Unsupported format. Use: json, csv, or --postman');
            return 1;
        }

        if ($output !== '') {
            file_put_contents($output, $content);
            $this->write('Exported ' . count($traces) . ' traces to ' . $output);
        } else {
            $this->write($content);
        }

        return 0;
    }

    private function exportPostman(string $traceId): int
    {
        if ($traceId === '') {
            $this->write('Usage: php siro log:export <trace_id> --postman');
            $this->write('Generate a Postman-compatible curl command from a trace.');
            return 1;
        }

        $traceFile = $this->basePath
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'traces'
            . DIRECTORY_SEPARATOR . $traceId . '.json';

        if (!is_file($traceFile)) {
            $this->write('Trace not found: ' . $traceId);
            return 1;
        }

        $data = json_decode((string) file_get_contents($traceFile), true);
        if (!is_array($data)) {
            $this->write('Invalid trace file.');
            return 1;
        }

        $method = $data['method'] ?? 'GET';
        $path = $data['path'] ?? '/';
        $host = 'http://localhost:8000';
        $headers = [];
        $body = '';

        $requestHeaders = $data['request_headers'] ?? [];
        if (is_array($requestHeaders)) {
            foreach ($requestHeaders as $k => $v) {
                if (strtolower($k) !== 'host') {
                    $headers[] = "-H '{$k}: {$v}'";
                }
            }
        }

        if (isset($data['auth_header']) && $data['auth_header'] !== '') {
            $headers[] = "-H 'Authorization: " . $data['auth_header'] . "'";
        }

        $requestBody = $data['request_body'] ?? '';
        if ($requestBody !== '' && $requestBody !== '[]' && $requestBody !== '{}') {
            $body = "  -d '" . str_replace("'", "'\\''", $requestBody) . "'";
        }

        $curlCmd = "curl -X {$method} {$host}{$path}";
        if ($headers !== []) {
            $curlCmd .= " \\\n  " . implode(" \\\n  ", $headers);
        }
        if ($body !== '') {
            $curlCmd .= " \\\n" . $body;
        }

        $this->write('');
        $this->write('  Postman-compatible curl:');
        $this->write('');
        $this->write('  ' . $curlCmd);
        $this->write('');
        $this->write('  Import into Postman:');
        $this->write('    Copy the curl command above');
        $this->write('    Postman → Import → Raw text → Paste → Continue');
        $this->write('');

        return 0;
    }

    /**
     * @param list<array<mixed>> $traces
     */
    private function toCsv(array $traces): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, ['timestamp', 'method', 'path', 'status', 'time_ms', 'ip']);

        foreach ($traces as $t) {
            fputcsv($handle, [
                $t['timestamp'] ?? '',
                $t['method'] ?? '',
                $t['path'] ?? '',
                $t['status'] ?? '',
                $t['time_ms'] ?? '',
                $t['ip'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }
}
