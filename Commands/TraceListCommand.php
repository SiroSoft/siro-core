<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * List recent request traces.
 *
 * Shows the most recent trace files with method, status,
 * response time, and path for quick browsing.
 * Supports --limit=N to control how many traces to show.
 *
 * @package Siro\Core\Commands
 */
final class TraceListCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $limit = 20;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 8));
            }
        }

        $tracesDir = $this->getTracesDir($this->basePath);
        $files = $this->findTraceFiles($tracesDir);
        if ($files === []) {
            $this->write('  No traces found.');
            return 1;
        }

        rsort($files);
        $files = array_slice($files, 0, $limit);

        $this->write('');
        $this->write('  Latest traces:');
        $this->write('  ' . str_repeat('-', 65));
        $this->write('  #  Trace ID          Method   Status   Time     Path');
        $this->write('  ' . str_repeat('-', 65));

        $idx = 1;
        foreach ($files as $file) {
            $traceId = basename($file, '.json');
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) continue;

            $method = str_pad($this->safeStr($data['method'] ?? '?'), 7);
            $statusVal = $data['status'] ?? 0;
            $status = is_numeric($statusVal) ? (int) $statusVal : 0;
            $timeMsVal = $data['time_ms'] ?? 0;
            $timeMs = round(is_numeric($timeMsVal) ? (float) $timeMsVal : 0, 1);
            $statusStr = str_pad($this->safeStr($status), 6);
            $timeStr = str_pad($this->safeStr($timeMs) . 'ms', 8);
            $path = $this->safeStr($data['path'] ?? '/');

            $this->write(sprintf('  %-2d %-18s %s %s %s %s', $idx, $traceId, $method, $statusStr, $timeStr, $path));
            $idx++;
        }

        $this->write('  ' . str_repeat('-', 65));
        $this->write('  php siro log:trace <trace_id>  — View full trace');
        $this->write('  php siro log:replay <trace_id> — Replay request');

        return 0;
    }
}
