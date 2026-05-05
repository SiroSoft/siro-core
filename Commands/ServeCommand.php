<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class ServeCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $host = (string) ($args[0] ?? 'localhost');
        $port = (string) ($args[1] ?? '8000');

        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
            $this->write('Invalid host.');
            return 1;
        }

        if (!ctype_digit($port)) {
            $this->write('Invalid port.');
            return 1;
        }

        $publicPath = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        if (!is_dir($publicPath)) {
            $this->write('public directory not found at: ' . $publicPath);
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
        $this->write('  Debug:');
        $this->write('    php siro debug:last');
        $this->write('    php siro log:tail');
        $this->write('  ' . str_repeat('-', 50));
        $this->write('  Press Ctrl+C to stop.');
        $this->write('');

        $command = sprintf(
            '"%s" -S %s:%s -t "%s"',
            PHP_BINARY,
            $host,
            $port,
            $publicPath
        );

        passthru($command, $status);
        return (int) $status;
    }
}
