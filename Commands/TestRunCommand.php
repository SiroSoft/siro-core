<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Run the PHPUnit test suite (legacy passthrough runner).
 *
 * Forwards whitelisted flags (including --filter=, --testsuite=,
 * --testdox, --debug) plus positional paths to PHPUnit. Runs from the
 * project root (chdir to basePath) so phpunit.xml is discovered even
 * when the caller runs from another directory (TQ-M9).
 *
 * Coverage is OFF by default (TQ-M14): phpunit.xml scaffolds ship a
 * <coverage> block, so without --no-coverage PHPUnit 13 tries to enable
 * coverage and runs 0 tests when no driver (xdebug/pcov) is installed.
 * Pass any --coverage* flag to opt back into coverage.
 *
 * Usage:
 *   php siro test:run [--filter=name] [--testsuite=Cli] [--testdox]
 *     [--stop-on-failure] [--group=x] [--no-coverage] [path...]
 *
 * @package Siro\Core\Commands
 */
final class TestRunCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
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

        $allowedPassthruFlags = ['--filter=', '--testsuite=', '--stop-on-failure', '--stop-on-error', '--group=',
            '--exclude-group=', '--colors=', '--testdox', '--prepend=', '--coverage-html=', '--coverage-text=',
            '--coverage-clover=', '--log-junit=', '--debug', '--no-configuration', '--bootstrap=', '-c', '--configuration=',
            '--no-coverage'];

        // Parse flags, forward only whitelisted ones to PHPUnit
        foreach ($args as $arg) {
            if ($arg === '-v' || $arg === '--verbose') {
                $verbose = true;
                continue;
            }
            // Non-flag args (file paths, etc.) are allowed (escaped for safety)
            if (!str_starts_with($arg, '-')) {
                $passthru[] = escapeshellarg($arg);
                continue;
            }
            $matched = false;
            foreach ($allowedPassthruFlags as $flag) {
                if ($arg === $flag || str_starts_with($arg, $flag)) {
                    $passthru[] = $arg;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                if (str_starts_with($arg, '--filter=')) {
                    $hasFilter = true;
                }
                if (str_starts_with($arg, '--testsuite=')) {
                    $hasSuite = true;
                }
            }
        }

        // --no-coverage by default (TQ-M14): without it, a phpunit.xml
        // <coverage> block makes PHPUnit 13 try coverage and run 0 tests
        // when no driver is installed. Only skip it when the user asked
        // for coverage output, or already passed --no-coverage.
        $wantsCoverage = false;
        $hasNoCoverage = false;
        foreach ($passthru as $flag) {
            if (str_starts_with($flag, '--coverage')) {
                $wantsCoverage = true;
            }
            if ($flag === '--no-coverage') {
                $hasNoCoverage = true;
            }
        }
        if (!$wantsCoverage && !$hasNoCoverage) {
            array_unshift($passthru, '--no-coverage');
        }

        // Run from the project root (TQ-M9): phpunit discovers
        // phpunit.xml (and relative test paths) from the cwd.
        if (is_dir($this->basePath)) {
            chdir($this->basePath);
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
