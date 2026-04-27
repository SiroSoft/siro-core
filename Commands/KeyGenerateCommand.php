<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class KeyGenerateCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        unset($args);

        $envPath = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            $examplePath = $this->basePath . DIRECTORY_SEPARATOR . '.env.example';
            if (is_file($examplePath)) {
                copy($examplePath, $envPath);
                $this->write('Created .env from .env.example');
            } else {
                $this->write('Cannot generate key: .env and .env.example not found.');
                return 1;
            }
        }

        $secret = bin2hex(random_bytes(32));
        $content = (string) file_get_contents($envPath);

        if (preg_match('/^JWT_SECRET=.*/m', $content) === 1) {
            $content = (string) preg_replace('/^JWT_SECRET=.*/m', 'JWT_SECRET=' . $secret, $content);
        } else {
            $content = rtrim($content) . PHP_EOL . 'JWT_SECRET=' . $secret . PHP_EOL;
        }

        file_put_contents($envPath, $content);
        $this->write('JWT_SECRET generated successfully.');

        return 0;
    }
}
