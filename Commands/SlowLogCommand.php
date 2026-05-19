<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class SlowLogCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $limit = 10;
        $minMs = 100;
        $tracesDir = $this->getTracesDir($this->basePath);
        $slowFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'slow.log';

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
        $files = $this->findTraceFiles($tracesDir);
        rsort($files);
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $timeMsVal = $data['time_ms'] ?? 0;
            $timeMs = is_numeric($timeMsVal) ? (float) $timeMsVal : 0;
            if ($timeMs >= $minMs) {
                $entries[] = [
                    'trace' => basename($file, '.json'),
                    'time' => $this->safeStr($data['timestamp'] ?? '?'),
                    'method' => $this->safeStr($data['method'] ?? '?'),
                    'path' => $this->safeStr($data['path'] ?? '?'),
                    'status' => $this->safeStr($data['status'] ?? '?'),
                    'ms' => $timeMs,
                    'queries' => isset($data['queries']) && is_array($data['queries']) ? count($data['queries']) : 0,
                ];
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
                array_map(function (int $i, array $e): array {
                    return [
                        $this->safeStr($i + 1),
                        $this->safeStr($e['time']),
                        $this->safeStr($e['method']),
                        $this->safeStr($e['path']),
                        $this->safeStr($e['status']),
                        round($e['ms'], 1) . 'ms',
                        $this->safeStr($e['queries']),
                    ];
                }, array_keys($entries), $entries)
            );
        }

        $this->write('');
        $this->write("Trace details: php siro log:trace <trace_id>");

        return 0;
    }
}
