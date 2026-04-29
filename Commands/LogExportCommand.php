<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogExportCommand
{
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
            }
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
            $content = json_encode($traces, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } elseif ($format === 'csv') {
            $content = $this->toCsv($traces);
        } else {
            $this->write('Unsupported format. Use: json or csv');
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

    private function toCsv(array $traces): string
    {
        $handle = fopen('php://memory', 'r+');
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
