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
        $filter = '';
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'tests';

        if (!is_dir($dir)) {
            $this->write('No tests directory found.');
            return 1;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*_test.php') ?: [];
        $files = array_filter($files, fn (string $f): bool => is_file($f) && !str_ends_with($f, 'benchmark.php'));

        if ($files === []) {
            $this->write('No test files found (*_test.php).');
            return 0;
        }

        $start = microtime(true);
        $totalPassed = 0;
        $totalFailed = 0;
        $totalTests = 0;

        $this->write('Running all tests...');
        $this->write('');

        foreach ($files as $file) {
            $name = basename($file);
            $this->write("  \033[1;33m{$name}\033[0m");
            ob_start();
            $exitCode = 0;
            passthru("php \"{$file}\" 2>&1", $exitCode);
            $output = ob_get_clean();

            // Extract Passed/Failed counts
            preg_match('/Passed:\s*(\d+)/', $output, $p);
            preg_match('/Failed:\s*(\d+)/', $output, $f);

            $passed = (int) ($p[1] ?? 0);
            $failed = (int) ($f[1] ?? 0);
            $totalPassed += $passed;
            $totalFailed += $failed;
            $totalTests += ($passed + $failed);

            // Show summary line
            $color = $failed === 0 ? '32' : '31';
            echo "  \033[{$color}m  {$passed} passed, {$failed} failed\033[0m\n\n";
        }

        $elapsed = microtime(true) - $start;
        $color = $totalFailed === 0 ? '32' : '31';

        $this->write("  \033[1;{$color}m═══ Results: {$totalTests} tests, {$totalPassed} passed, {$totalFailed} failed in " . number_format($elapsed, 2) . "s ═══\033[0m");

        return $totalFailed > 0 ? 1 : 0;
    }
}
