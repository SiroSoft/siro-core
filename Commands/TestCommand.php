<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class TestCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $filter = '';
        $suite = '';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--filter=')) {
                $filter = substr($arg, 9);
            } elseif (str_starts_with($arg, '--suite=')) {
                $suite = substr($arg, 8);
            } elseif ($arg === '--coverage') {
                $filter = '--coverage';
            }
        }

        $phpunit = $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';

        if (!is_file($phpunit)) {
            $this->write('PHPUnit not found. Run: composer install --dev');
            return 1;
        }

        $cmd = 'php ' . escapeshellarg($phpunit) . ' --no-progress --colors=always';

        if ($filter !== '' && $filter !== '--coverage') {
            $cmd .= ' --filter=' . escapeshellarg($filter);
        }

        if ($suite !== '') {
            $cmd .= ' --testsuite=' . escapeshellarg($suite);
        }

        if ($filter === '--coverage' || in_array('--coverage', $args, true)) {
            $cmd .= ' --coverage-html=' . escapeshellarg($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'coverage');
            $this->write('Running tests with coverage report...');
            $this->write('Report will be available at: storage/coverage/index.html');
            $this->write('');
        }

        $this->write('Running: vendor/bin/phpunit' . ($filter ? " --filter={$filter}" : '') . ($suite ? " --testsuite={$suite}" : ''));
        $this->write('');

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
