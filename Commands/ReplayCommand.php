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
final class ReplayCommand implements \Siro\Core\Commands\CommandInterface {
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
            $files = $this->findTraceFiles($traceDir);
            if ($files === []) {
                $this->write('No traces found. Run an API request first.');
                return 1;
            }
            rsort($files);
            $traceId = basename($files[0], '.json');
            $this->write('Replaying last trace: ' . $traceId);
        }

        // Delegate: traceId FIRST, then flags
        $consoleArgs = array_merge(['siro', 'log:replay', $traceId], $flags);
        $_SERVER['argv'] = $consoleArgs;
        $_SERVER['argc'] = count($consoleArgs);

        $console = new \Siro\Core\Console($this->basePath);
        return $console->run($consoleArgs);
    }
}
