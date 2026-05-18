<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogTopCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $limit = 10;
        $minMs = 0;
        $tracesDir = $this->getTracesDir($this->basePath);

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--min=')) {
                $minMs = max(0, (int) substr($arg, 6));
            }
        }

        $files = $this->findTraceFiles($tracesDir);
        if ($files === []) {
            $this->write('No traces found.');
            return 0;
        }

        $aggregated = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) continue;

            $key = strtoupper($this->safeStr($data['method'] ?? 'GET')) . ' ' . $this->safeStr($data['path'] ?? '/');
            $timeMsVal = $data['time_ms'] ?? 0;
            $timeMs = is_numeric($timeMsVal) ? (float) $timeMsVal : 0;

            if ($timeMs < $minMs) continue;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'method' => strtoupper($this->safeStr($data['method'] ?? 'GET')),
                    'path' => $data['path'] ?? '/',
                    'count' => 0,
                    'total_ms' => 0,
                    'max_ms' => 0,
                    'avg_ms' => 0,
                ];
            }
            $aggregated[$key]['count']++;
            $aggregated[$key]['total_ms'] += $timeMs;
            $aggregated[$key]['max_ms'] = max($aggregated[$key]['max_ms'], $timeMs);
            $aggregated[$key]['avg_ms'] = $aggregated[$key]['total_ms'] / $aggregated[$key]['count'];
        }

        if ($aggregated === []) {
            $this->write('No slow requests found (> ' . $minMs . 'ms).');
            return 0;
        }

        $sortBy = $args[1] ?? 'total';

        $sorted = array_values($aggregated);
        usort($sorted, fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        $sorted = array_slice($sorted, 0, $limit);

        $this->write('');
        $this->write('  Top ' . $limit . ' slowest APIs (by total time):');
        $this->write('');

        $this->table(
            ['#', 'Method', 'Path', 'Count', 'Avg (ms)', 'Max (ms)', 'Total (s)'],
            /** @phpstan-ignore argument.type */
            array_map(fn ($i, $e) => [
                (string) ($i + 1),
                $e['method'],
                $e['path'],
                (string) $e['count'],
                round($e['avg_ms'], 1),
                round($e['max_ms'], 1),
                round($e['total_ms'] / 1000, 2),
            ], array_keys($sorted), $sorted)
        );

        $this->write('');
        $this->write('  Details: php siro log:slow');
        $this->write('  Trace:   php siro log:trace <trace_id>');

        return 0;
    }
}
