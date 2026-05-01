<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogCleanupCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $days = 7;
        $dryRun = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--days=')) {
                $days = max(1, (int) substr($arg, 7));
            } elseif ($arg === '--dry-run') {
                $dryRun = true;
            }
        }

        $traceDir = $this->basePath
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'traces';

        if (!is_dir($traceDir)) {
            $this->write('No traces directory found.');
            return 0;
        }

        $cutoff = time() - ($days * 86400);
        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];

        $deleted = 0;
        $skipped = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if ($dryRun) {
                    $skipped++;
                } else {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        if ($dryRun) {
            $this->write("Would delete {$skipped} trace files older than {$days} days.");
            $this->write('Run without --dry-run to execute.');
        } else {
            $remaining = count($files) - $deleted;
            $this->write("Deleted {$deleted} trace files older than {$days} days.");
            $this->write("Remaining: {$remaining} trace files.");
        }

        return 0;
    }
}
