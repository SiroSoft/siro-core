<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Replay the last trace or a specific trace by ID.
 *
 * Delegates to LogReplayCommand. If no trace_id provided,
 * automatically finds and replays the most recent trace.
 * Supports --edit, --diff flags passed through to log:replay.
 *
 * @package Siro\Core\Commands
 */
final class ReplayCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        // If trace_id provided as first arg
        $traceId = '';
        $extraArgs = ['siro', 'log:replay'];

        foreach ($args as $arg) {
            if ($traceId === '' && !str_starts_with($arg, '--')) {
                $traceId = $arg;
            } else {
                $extraArgs[] = $arg;
            }
        }

        // If no trace_id, find the last one
        if ($traceId === '') {
            $traceDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
            if (!is_dir($traceDir)) {
                $this->write('No traces found.');
                return 1;
            }
            $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            if ($files === []) {
                $this->write('No traces found. Run an API request first.');
                return 1;
            }
            rsort($files);
            $traceId = basename($files[0], '.json');
            $this->write('Replaying last trace: ' . $traceId);
        }

        // Delegate to log:replay
        $extraArgs[] = $traceId;
        $_SERVER['argv'] = $extraArgs;
        $_SERVER['argc'] = count($extraArgs);

        $console = new \Siro\Core\Console($this->basePath);
        return $console->run($extraArgs);
    }
}
