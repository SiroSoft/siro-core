<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class TestRunCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'tests';
        if (!is_dir($dir)) {
            $this->write('No tests directory found.');
            return 1;
        }

        $phpunitPath = $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        if (!is_file($phpunitPath)) {
            $this->write("  \033[33mPHPUnit not found. Run 'composer install' first.\033[0m");
            return 1;
        }

        $start = microtime(true);
        $hasFilter = false;
        $hasSuite = false;
        $verbose = false;
        $passthru = [];

        // Parse known flags, forward rest to PHPUnit
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--filter=')) {
                $hasFilter = true;
                $passthru[] = $arg;
            } elseif (str_starts_with($arg, '--testsuite=')) {
                $hasSuite = true;
                $passthru[] = $arg;
            } elseif ($arg === '-v' || $arg === '--verbose') {
                $verbose = true;
            } elseif ($arg === '--stop-on-failure' || $arg === '--stop-on-error') {
                $passthru[] = $arg;
            } else {
                $passthru[] = escapeshellarg($arg);
            }
        }

        $this->write("  \033[1;33mPHPUnit Test Suite\033[0m");
        if ($hasFilter) {
            $this->write("  Filter: " . implode(' ', array_filter($args, fn($a) => str_starts_with($a, '--filter='))));
        }
        if ($hasSuite) {
            $this->write("  Suite: " . implode(' ', array_filter($args, fn($a) => str_starts_with($a, '--testsuite='))));
        }
        $this->write('');

        // Build command
        $progress = $verbose ? '' : '--no-progress';
        $extra = $passthru !== [] ? ' ' . implode(' ', $passthru) : '';
        $cmd = "\"{$phpunitPath}\" {$progress}{$extra} 2>&1";

        passthru($cmd, $exitCode);

        $elapsed = microtime(true) - $start;
        $color = $exitCode === 0 ? '32' : '31';
        $this->write('');
        $this->write("  \033[1;{$color}m═══ Done in " . number_format($elapsed, 2) . "s ═══\033[0m");

        return $exitCode;
    }
}
