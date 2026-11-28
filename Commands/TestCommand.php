<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Run the PHPUnit test suite.
 *
 * Runs from the project root (chdir to basePath) so phpunit.xml is
 * discovered even when the caller runs from another directory (TQ-M9).
 *
 * Usage:
 *   php siro test [--filter=name] [--suite=Unit] [--coverage]
 *
 * @package Siro\Core\Commands
 */
final class TestCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $filter = '';
        $suite = '';
        $coverage = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--filter=')) {
                $filter = substr($arg, 9);
            } elseif (str_starts_with($arg, '--suite=')) {
                $suite = substr($arg, 8);
            } elseif ($arg === '--coverage') {
                $coverage = true;
            }
        }

        $phpunit = $this
            ->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';

        if (!is_file($phpunit)) {
            $this->write('PHPUnit not found. Run: composer install --dev');
            return 1;
        }

        $cmd = 'php ' . escapeshellarg($phpunit) . ' --no-coverage --no-progress --colors=always';

        if ($filter !== '') {
            $cmd .= ' --filter=' . escapeshellarg($filter);
        }

        if ($suite !== '') {
            $cmd .= ' --testsuite=' . escapeshellarg($suite);
        }

        if ($coverage) {
            $cmd .= ' --coverage-html=' . escapeshellarg($this
                ->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'coverage');
            $this->write('Running tests with coverage report...');
            $this->write('Report will be available at: storage/coverage/index.html');
            $this->write('');
        }

        $this
            ->write('Running: vendor/bin/phpunit' . ($filter ? " --filter={$filter}" : '') . ($suite ?
            " --testsuite={$suite}" : ''));
        $this->write('');

        // Run from the project root (TQ-M9): phpunit discovers
        // phpunit.xml from the cwd.
        if (is_dir($this->basePath)) {
            chdir($this->basePath);
        }

        passthru($cmd, $exitCode);

        if ($exitCode === 0) {
            $this->write('');
            $this->success('All tests passed.');
        } else {
            $this->write('');
            $this->error('Some tests failed.');
        }

        return $exitCode;
    }
}
