<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class NewCommand
{
    use CommandSupport;

    private const SKELETON_DIRS = [
        'app/Controllers',
        'app/Middleware',
        'app/Models',
        'app/Services',
        'config',
        'database/migrations',
        'database/seeds',
        'docs/openapi',
        'docs/swagger',
        'docs/postman',
        'lang/en',
        'lang/vi',
        'public',
        'routes',
        'storage/app',
        'storage/cache',
        'storage/logs/traces',
        'storage/public',
        'storage/rate_limit',
        'tests/Unit',
        'tests/Integration',
        'tests/Feature',
    ];

    private const SKELETON_FILES = [
        '.env.example',
        '.gitignore',
        'README.md',
        'composer.json',
        'phpunit.xml',
        'siro',
        'config/database.php',
        'routes/api.php',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = $args[0] ?? '';
        if ($name === '') {
            $this->write('Usage: php siro new <project-name>');
            $this->write('  Creates a new SiroPHP project in ./<project-name>/');
            return 1;
        }

        $targetDir = getcwd() . DIRECTORY_SEPARATOR . $name;

        if (is_dir($targetDir)) {
            $this->write("Error: Directory '{$name}' already exists.");
            return 1;
        }

        $this->write("Creating SiroPHP project: \033[1;33m{$name}\033[0m");
        $this->write('');

        // Create directory structure
        foreach (self::SKELETON_DIRS as $dir) {
            $full = $targetDir . DIRECTORY_SEPARATOR . $dir;
            if (!mkdir($full, 0755, true) && !is_dir($full)) {
                $this->write("  \033[31m✗ Failed to create: {$dir}\033[0m");
                return 1;
            }
        }

        // Copy skeleton files from current project
        $copied = 0;
        $generated = 0;

        foreach (self::SKELETON_FILES as $file) {
            $src = $this->basePath . DIRECTORY_SEPARATOR . $file;
            $dst = $targetDir . DIRECTORY_SEPARATOR . $file;

            if (is_file($src)) {
                $dir = dirname($dst);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $content = file_get_contents($src);
                if ($content === false) continue;
                // Replace placeholder project name
                $content = str_replace('Siro API Framework', $name, $content);
                $content = str_replace('sirosoft/api', $name, $content);
                file_put_contents($dst, $content);
                $copied++;
                $this->write("  \033[32m✓\033[0m Created: {$file}");
            }
        }

        // Generate .env from .env.example
        if (!is_file($targetDir . DIRECTORY_SEPARATOR . '.env.example')) {
            $envContent = "APP_NAME=\"{$name}\"\nAPP_ENV=local\nAPP_DEBUG=true\nJWT_SECRET=\n";
            file_put_contents($targetDir . DIRECTORY_SEPARATOR . '.env', $envContent);
            $generated++;
        }

        // Create .gitkeep files for empty dirs
        $gitkeepDirs = ['storage/app', 'storage/cache', 'storage/logs/traces', 'storage/public', 'storage/rate_limit'];
        foreach ($gitkeepDirs as $dir) {
            file_put_contents($targetDir . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . '.gitkeep', '');
        }

        // Generate JWT key
        $jwtSecret = bin2hex(random_bytes(32));
        $envPath = $targetDir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envPath)) {
            $env = file_get_contents($envPath);
            $env = str_replace('JWT_SECRET=', 'JWT_SECRET=' . $jwtSecret, $env);
            file_put_contents($envPath, $env);
        }

        $this->write('');
        $this->write("  \033[1;32m✓ Project '{$name}' created successfully!\033[0m");
        $this->write('');
        $this->write('  Next steps:');
        $this->write("    cd {$name}");
        $this->write('    composer install');
        $this->write('    php siro key:generate');
        $this->write('    php siro serve');
        $this->write('');

        return 0;
    }
}
