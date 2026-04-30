<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class SlowLogCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $limit = 10;
        $minMs = 100;
        $tracesDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        $slowFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'slow.log';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--min=')) {
                $minMs = max(1, (int) substr($arg, 6));
            }
        }

        $this->write("Top {$limit} slow requests (> {$minMs}ms):");
        $this->write('');

        // Method 1: Parse trace files
        $entries = [];
        if (is_dir($tracesDir)) {
            $files = glob($tracesDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            rsort($files);
            foreach ($files as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data)) {
                    continue;
                }
                $timeMs = (float) ($data['time_ms'] ?? 0);
                if ($timeMs >= $minMs) {
                    $entries[] = [
                        'trace' => basename($file, '.json'),
                        'time' => $data['timestamp'] ?? '?',
                        'method' => $data['method'] ?? '?',
                        'path' => $data['path'] ?? '?',
                        'status' => (string) ($data['status'] ?? '?'),
                        'ms' => $timeMs,
                        'queries' => isset($data['queries']) ? count($data['queries']) : 0,
                    ];
                }
            }
        }

        // Method 2: Parse slow.log
        $slowEntries = [];
        if (is_file($slowFile)) {
            $lines = file($slowFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach (array_reverse($lines) as $line) {
                    if (preg_match('/(\d+\.?\d*)ms.*?(\w+)\s+(\/\S+)/', $line, $m)) {
                        $slowEntries[] = [
                            'line' => $line,
                            'ms' => (float) $m[1],
                            'method' => $m[2],
                            'path' => $m[3],
                        ];
                    }
                }
            }
        }

        // Sort by time descending
        usort($entries, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $entries = array_slice($entries, 0, $limit);

        if ($entries === [] && $slowEntries === []) {
            $this->write('  No slow requests found.');
            return 0;
        }

        if ($entries !== []) {
            $this->table(
                ['#', 'Time', 'Method', 'Path', 'Status', 'Duration', 'SQL'],
                array_map(fn ($i, $e) => [
                    (string) ($i + 1),
                    $e['time'],
                    $e['method'],
                    $e['path'],
                    $e['status'],
                    round($e['ms'], 1) . 'ms',
                    (string) $e['queries'],
                ], array_keys($entries), $entries)
            );
        }

        $this->write('');
        $this->write("Trace details: php siro log:trace <trace_id>");

        return 0;
    }
}
