<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class EnvSwitchCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $env = strtolower(trim($args[0] ?? ''));
        if ($env === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $env)) {
            $this->write('Usage: php siro env:switch <environment>');
            $this->write('Environments: local, testing, staging, production');
            $this->write('Switches .env to .env.<environment> and copies .env back.');
            return 1;
        }

        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        $profileFile = $this->basePath . DIRECTORY_SEPARATOR . ".env.{$env}";

        if (!is_file($profileFile)) {
            // Allow restoring the pre-switch .env from the backup
            $backup = $this->basePath . DIRECTORY_SEPARATOR . '.env.backup';
            if (is_file($backup)) {
                copy($backup, $envFile);
                $this->write("Environment restored from .env.backup");
                return 0;
            }
            $this->write("Environment file not found: .env.{$env}");
            $this->write('Create it first: cp .env ' . ".env.{$env}");
            return 1;
        }

        // Backup current .env
        if (is_file($envFile)) {
            $backup = $this->basePath . DIRECTORY_SEPARATOR . '.env.backup';
            copy($envFile, $backup);
        }

        copy($profileFile, $envFile);

        $this->write("Environment switched to: \033[1;33m{$env}\033[0m");
        $this->write("  Copied .env.{$env} → .env");
        $this->write("  Backup saved as .env.backup");

        return 0;
    }
}
