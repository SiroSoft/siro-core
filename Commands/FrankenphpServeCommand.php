<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Start FrankenPHP production-grade server.
 *
 * Uses the FrankenPHP binary (if available) or Docker.
 * Multi-worker, HTTP/2, HTTP/3, automatic HTTPS.
 *
 * Usage:
 *   php siro frankenphp:serve            # Start FrankenPHP
 *   php siro frankenphp:serve --docker   # Start via Docker
 *   php siro frankenphp:serve --port=8080
 *
 * @package Siro\Core\Commands
 */
final class FrankenphpServeCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $useDocker = false;
        $port = '80';
        $dockerPort = '80';

        foreach ($args as $arg) {
            if ($arg === '--docker' || $arg === '-d') {
                $useDocker = true;
            } elseif (str_starts_with($arg, '--port=')) {
                $port = substr($arg, 7);
                $dockerPort = $port;
            } elseif (str_starts_with($arg, '--docker-port=')) {
                $dockerPort = substr($arg, 14);
            }
        }

        if (!ctype_digit($port)) {
            $this->write('  Invalid port.');
            return 1;
        }

        if ($useDocker) {
            return $this->runDocker($dockerPort);
        }

        return $this->runLocal($port);
    }

    private function runLocal(string $port): int
    {
        // Check if FrankenPHP binary exists
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'which';

        $output = shell_exec("{$which} frankenphp 2>/dev/null");
        if ($output === null || $output === '') {
            $this->write('');
            $this->write('  ⚠ FrankenPHP binary not found.');
            $this->write('');
            $this->write('  Install: https://frankenphp.dev/install/');
            $this->write('  Or use Docker: php siro frankenphp:serve --docker');
            $this->write('');
            $this->write('  Fallback: php siro serve (uses php -S)');
            $this->write('');
            return 1;
        }

        $caddyfile = $this->basePath . '/frankenphp/Caddyfile';
        if (!file_exists($caddyfile)) {
            $this->write('  Caddyfile not found at: ' . $caddyfile);
            return 1;
        }

        putenv('SERVER_NAME=:' . $port);

        $this->write('');
        $this->write('  ⚡ FrankenPHP server at http://localhost:' . $port);
        $this->write('  ' . str_repeat('-', 50));
        $this->write('  Workers: auto (multi-process)');
        $this->write('  HTTP/2:  enabled');
        $this->write('  HTTP/3:  enabled (UDP)');
        $this->write('  ' . str_repeat('-', 50));
        $this->write('  Press Ctrl+C to stop.');
        $this->write('');

        $command = sprintf(
            'frankenphp run --config "%s"',
            $caddyfile
        );

        passthru($command, $status);
        return (int) $status;
    }

    private function runDocker(string $port): int
    {
        $this->write('');
        $this->write('  Starting FrankenPHP via Docker...');
        $this->write('');

        $command = sprintf(
            'docker build -f Dockerfile.frankenphp -t siro-app:latest "%s" && docker run -p %s:80 -p 443:443 -v "%s/.env:/app/.env" siro-app:latest',
            $this->basePath,
            $port,
            $this->basePath
        );

        $this->write('  Building image...');
        $this->write('');

        passthru($command, $status);
        return (int) $status;
    }
}
