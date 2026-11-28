<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Replay the last trace or a specific trace by ID.
 *
 * Delegates to LogReplayCommand. If no trace_id provided,
 * automatically finds and replays the most recent trace.
 * Supports --edit, --diff, --dry-run flags passed through to log:replay.
 *
 * @package Siro\Core\Commands
 */
final class ReplayCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $traceId = '';
        $flags = [];

        foreach ($args as $arg) {
            if ($traceId === '' && !str_starts_with($arg, '--')) {
                $traceId = $arg;
            } else {
                $flags[] = $arg;
            }
        }

        if ($traceId === '') {
            $traceDir = $this->getTracesDir($this->basePath);
            $rawFiles = $this->findTraceFiles($traceDir);
            if ($rawFiles === []) {
                $this->write('No traces found. Run an API request first.');
                return 1;
            }
            // filemtime() has 1-second resolution: traces created (or
            // copied) within the same second tie, and usort then keeps
            // directory order — so "last trace" was arbitrary and could
            // pick an old bundled trace over a fresh one. Sort by the
            // timestamp recorded INSIDE the trace (the true request time,
            // unaffected by copying), then mtime, then filename — fully
            // deterministic. (Content timestamps are also second
            // resolution, so two traces in the same second still fall
            // back to mtime/filename order.)
            usort($rawFiles, static function (string $a, string $b): int {
                $ta = self::traceTimestamp($a);
                $tb = self::traceTimestamp($b);
                if ($ta !== $tb) {
                    return $tb <=> $ta;
                }
                $ma = filemtime($a);
                $mb = filemtime($b);
                $ma = $ma === false ? 0 : $ma;
                $mb = $mb === false ? 0 : $mb;
                if ($ma !== $mb) {
                    return $mb <=> $ma;
                }
                return strcmp(basename($b), basename($a));
            });
            $traceId = basename($rawFiles[0], '.json');
            $this->write('Replaying last trace: ' . $traceId);
        }

        // Delegate: traceId FIRST, then flags
        $consoleArgs = array_merge(['siro', 'log:replay', $traceId], $flags);
        $_SERVER['argv'] = $consoleArgs;
        $_SERVER['argc'] = count($consoleArgs);

        $console = new \Siro\Core\Console($this->basePath);
        return $console->run($consoleArgs);
    }

    /**
     * Request time recorded inside a trace file, as unix seconds
     * (0 when the file is unreadable or has no usable timestamp).
     * Accepts numeric epochs and date strings ("2026-11-27 03:15:19",
     * ISO 8601, …) via strtotime().
     */
    private static function traceTimestamp(string $file): int
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return 0;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return 0;
        }
        $ts = $data['timestamp'] ?? null;
        if (is_numeric($ts)) {
            return (int) $ts;
        }
        if (is_string($ts) && $ts !== '') {
            $parsed = strtotime($ts);
            return $parsed === false ? 0 : $parsed;
        }
        return 0;
    }
}
