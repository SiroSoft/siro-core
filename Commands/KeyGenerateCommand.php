<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class KeyGenerateCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Generate a secure JWT secret.
 *
 * Creates a random 64-character hex string and writes it
 * to the JWT_SECRET key in .env.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $force = in_array('--force', $args, true);

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

        $appEnv = strtolower(trim((string) \Siro\Core\Env::get('APP_ENV', 'production')));
        $hasSecret = is_string(getenv('JWT_SECRET')) && getenv('JWT_SECRET') !== '';

        if (($appEnv === 'production' || $hasSecret) && !$force) {
            $this->write('  Refusing to rotate JWT_SECRET on an existing/production environment.');
            $this->write('  This would invalidate all signed tokens.');
            $this->write('  Run with --force to overwrite anyway: php siro key:generate --force');
            return 1;
        }

        $secret = bin2hex(random_bytes(32));
        $content = (string) file_get_contents($envPath);

        if (preg_match('/^JWT_SECRET=.*/m', $content) === 1) {
            $content = (string) preg_replace('/^JWT_SECRET=.*/m', 'JWT_SECRET=' . $secret, $content);
        } else {
            $content = rtrim($content) . PHP_EOL . 'JWT_SECRET=' . $secret . PHP_EOL;
        }

        $written = @file_put_contents($envPath, $content);
        if ($written === false) {
            $this->write('  Error: could not write JWT_SECRET to ' . $envPath);
            return 1;
        }
        $this->write('JWT_SECRET generated successfully.');

        return 0;
    }
}
