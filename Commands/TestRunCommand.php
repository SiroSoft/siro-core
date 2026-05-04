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

        $start = microtime(true);

        // Run PHPUnit tests
        $phpunitPath = $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        if (is_file($phpunitPath)) {
            $this->write("  \033[1;33mPHPUnit Test Suite\033[0m");
            $output = [];
            $exitCode = 0;
            exec("\"{$phpunitPath}\" --no-progress 2>&1", $output, $exitCode);
            $outputText = implode("\n", $output);

            // Parse PHPUnit output
            $totalTests = 0;
            $totalPassed = 0;
            $totalFailed = 0;

            if (preg_match('/(?:OK|FAILURES!).*Tests:\s+(\d+)/s', $outputText, $m)) {
                $totalTests = (int) $m[1];
                if (preg_match('/Failures:\s+(\d+)/s', $outputText, $fm)) {
                    $totalFailed = (int) $fm[1];
                }
                if (preg_match('/Errors:\s+(\d+)/s', $outputText, $em)) {
                    $totalFailed += (int) $em[1];
                }
                $totalPassed = $totalTests - $totalFailed;
                $color = $totalFailed === 0 ? '32' : '31';
                echo "   \033[{$color}m  {$totalTests} tests, {$totalPassed} passed, {$totalFailed} failed\033[0m\n\n";
            } elseif (preg_match('/OK\s+\((\d+)\s+test/', $outputText, $m)) {
                $totalTests = (int) $m[1];
                $totalPassed = (int) $m[1];
                echo "   \033[32m  {$totalPassed} passed, 0 failed\033[0m\n\n";
            } else {
                echo "   \033[33m  PHPUnit finished (output not parsed)\033[0m\n\n";
            }

            $elapsed = microtime(true) - $start;
            $color = $totalFailed === 0 ? '32' : '31';
            $this->write("  \033[1;{$color}m═══ Results: {$totalTests} tests, {$totalPassed} passed, {$totalFailed} failed in " . number_format($elapsed, 2) . "s ═══\033[0m");

            return $totalFailed > 0 ? 1 : 0;
        }

        $this->write("  \033[33mPHPUnit not found. Run 'composer install' first.\033[0m");
        return 1;
    }
}
