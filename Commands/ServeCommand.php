<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Start the PHP built-in development server.
 *
 * Launches php -S on the configured host and port, serving
 * from the public/ directory. Shows quick test and debug
 * commands after startup.
 *
 * @package Siro\Core\Commands
 */
final class ServeCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $host = 'localhost';
        $port = '8080';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $port = substr($arg, 7);
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            } elseif (ctype_digit($arg)) {
                $port = $arg;
            } elseif (!str_starts_with($arg, '--')) {
                $host = $arg;
            }
        }

        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
            $this->write('Invalid host.');
            return 1;
        }

        if (!ctype_digit($port)) {
            $this->write('Invalid port.');
            return 1;
        }

        $publicPath = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        $routerScript = $publicPath . DIRECTORY_SEPARATOR . 'router.php';

        if (!is_dir($publicPath)) {
            $this->write('public directory not found at: ' . $publicPath);
            return 1;
        }

        if (!file_exists($routerScript)) {
            $this->write('Router script not found at: ' . $routerScript);
            $this->write('Make sure public/router.php exists.');
            return 1;
        }

        $this->write('');
        $this->write('  ⚡ Siro dev server at http://' . $host . ':' . $port);
        $this->write('  ' . str_repeat('-', 50));
        $this->write('  Quick test:');
        $this->write('    php siro api:test GET /api/users');
        $this->write('    php siro api:test POST /api/auth/login email=admin@test.com password=secret');
        $this->write('    php siro api:test POST /api/products name=Laptop price=999 --as=admin');
        $this->write('');
        $this->write('  Probes:');
        $this->write('    curl http://' . $host . ':' . $port . '/health/live');
        $this->write('    curl http://' . $host . ':' . $port . '/health/ready');
        $this->write('');
        $this->write('  Debug:');
        $this->write('    php siro debug:last');
        $this->write('    php siro log:tail');
        $this->write('  ' . str_repeat('-', 50));
        $this->write('  Press Ctrl+C to stop.');
        $this->write('');

        $command = sprintf(
            '%s -S %s:%s -t %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($publicPath),
            escapeshellarg($routerScript)
        );

        passthru($command, $status);
        return (int) $status;
    }
}
