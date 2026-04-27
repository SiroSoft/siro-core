<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class ServeCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
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

        $this->write("Starting Siro server at http://{$host}:{$port}");
        $this->write('Press Ctrl+C to stop.');

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
