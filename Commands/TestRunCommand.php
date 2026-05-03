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
        $runPhpunit = in_array('--phpunit', $args, true);
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'tests';

        if (!is_dir($dir)) {
            $this->write('No tests directory found.');
            return 1;
        }

        $start = microtime(true);
        $totalPassed = 0;
        $totalFailed = 0;
        $totalTests = 0;

        // Run PHPUnit tests if vendor/bin/phpunit exists
        $phpunitPath = $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        if ($runPhpunit && is_file($phpunitPath)) {
            $this->write("  \033[1;33mPHPUnit Tests\033[0m");
            $output = [];
            $exitCode = 0;
            exec("\"{$phpunitPath}\" --no-progress 2>&1", $output, $exitCode);
            $outputText = implode("\n", $output);

            // Parse PHPUnit output: "OK (136 tests, 184 assertions)"
            if (preg_match('/OK\s+\((\d+)\s+test/', $outputText, $m)) {
                $totalPassed += (int) $m[1];
                $totalTests += (int) $m[1];
                echo "   \033[32m  {$m[1]} passed, 0 failed\033[0m\n\n";
            } elseif (preg_match('/FAILURES!.*Tests:\s+(\d+).*Failures:\s+(\d+)/s', $outputText, $m)) {
                $totalPassed += (int) $m[1] - (int) $m[2];
                $totalFailed += (int) $m[2];
                $totalTests += (int) $m[1];
                echo "   \033[31m  {$m[1]} tests, {$m[2]} failures\033[0m\n\n";
            } else {
                echo "   \033[33m  PHPUnit output unclear, check manually\033[0m\n\n";
            }
        }

        // Run custom script tests (*_test.php in tests/ and subdirs)
        $files = glob($dir . DIRECTORY_SEPARATOR . '*_test.php') ?: [];
        $subFiles = glob($dir . DIRECTORY_SEPARATOR . '*_test.php') ?: [];
        $subDirs = ['unit', 'integration'];
        foreach ($subDirs as $sd) {
            $subDir = $dir . DIRECTORY_SEPARATOR . $sd;
            if (is_dir($subDir)) {
                $found = glob($subDir . DIRECTORY_SEPARATOR . '*_test.php') ?: [];
                $files = array_merge($files, $found);
            }
        }
        $files = array_filter($files, fn (string $f): bool => is_file($f) && !str_ends_with($f, 'benchmark.php'));

        if ($files !== []) {
            foreach ($files as $file) {
                $name = basename($file);
                $this->write("  \033[1;33m{$name}\033[0m");
                ob_start();
                $exitCode = 0;
                passthru("php \"{$file}\" 2>&1", $exitCode);
                $output = ob_get_clean();

                preg_match('/Passed:\s*(\d+)/', $output, $p);
                preg_match('/Failed:\s*(\d+)/', $output, $f);

                $passed = (int) ($p[1] ?? 0);
                $failed = (int) ($f[1] ?? 0);
                $totalPassed += $passed;
                $totalFailed += $failed;
                $totalTests += ($passed + $failed);

                $color = $failed === 0 ? '32' : '31';
                echo "  \033[{$color}m  {$passed} passed, {$failed} failed\033[0m\n\n";
            }
        }

        $elapsed = microtime(true) - $start;
        $color = $totalFailed === 0 ? '32' : '31';

        $this->write("  \033[1;{$color}m═══ Results: {$totalTests} tests, {$totalPassed} passed, {$totalFailed} failed in " . number_format($elapsed, 2) . "s ═══\033[0m");

        return $totalFailed > 0 ? 1 : 0;
    }
}
