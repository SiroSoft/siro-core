<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class NewCommand implements \Siro\Core\Commands\CommandInterface {
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



    public function __construct(private readonly string $basePath)
    {
    }

    private function getSkeletonDir(): string
    {
        // When running from PHAR, skeleton is bundled inside
        if (str_starts_with($this->basePath, 'phar://')) {
            $pharPath = 'phar://' . $this->basePath . '/skeleton';
            if (is_dir($pharPath)) {
                return $pharPath;
            }
        }
        // Fallback: adjacent SiroPHP directory
        $candidate = dirname($this->basePath, 2) . DIRECTORY_SEPARATOR . 'SiroPHP';
        if (is_dir($candidate)) {
            return $candidate;
        }
        // Last resort: use basePath itself (running from within SiroPHP)
        return $this->basePath;
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

        $skeletonDir = $this->getSkeletonDir();

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

        // Recursively copy skeleton (replaces ../siro-core path repo)
        $copied = $this->copySkeleton($skeletonDir, $targetDir, $name);

        // Always create .env from .env.example
        $envPath = $targetDir . DIRECTORY_SEPARATOR . '.env';
        $envExample = $targetDir . DIRECTORY_SEPARATOR . '.env.example';
        if (is_file($envExample) && !is_file($envPath)) {
            copy($envExample, $envPath);
        }
        if (!is_file($envPath)) {
            $envContent = "APP_NAME=\"{$name}\"\nAPP_ENV=local\nAPP_DEBUG=true\nDB_CONNECTION=sqlite\nDB_DATABASE=storage/app/database.sqlite\nJWT_SECRET=\n";
            file_put_contents($envPath, $envContent);
        }

        // Create .gitkeep files for empty dirs
        $gitkeepDirs = ['storage/app', 'storage/cache', 'storage/logs/traces', 'storage/public', 'storage/rate_limit'];
        foreach ($gitkeepDirs as $dir) {
            $path = $targetDir . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . '.gitkeep';
            if (!is_file($path)) {
                file_put_contents($path, '');
            }
        }

        // Generate JWT key
        $jwtSecret = bin2hex(random_bytes(32));
        if (is_file($envPath)) {
            $env = file_get_contents($envPath);
            if ($env !== false) {
                if (str_contains($env, 'JWT_SECRET=')) {
                    $env = preg_replace('/^JWT_SECRET=.*$/m', 'JWT_SECRET=' . $jwtSecret, $env);
                } else {
                    $env .= "\nJWT_SECRET=" . $jwtSecret . "\n";
                }
                file_put_contents($envPath, $env);
            }
        }

        $this->write('');
        $this->write("  \033[1;32m✓ Project '{$name}' created successfully!\033[0m");
        $this->write('');
        $this->write('  Next steps:');
        $this->write("    cd {$name}");
        $this->write('    composer install');
        $this->write('    php siro serve');
        $this->write('');

        return 0;
    }

    private function copySkeleton(string $src, string $dst, string $projectName): int
    {
        $count = 0;
        $ds = DIRECTORY_SEPARATOR;
        $exclude = ['vendor', '.git',
            "storage{$ds}logs", "storage{$ds}benchmark", "storage{$ds}sbom",
            "storage{$ds}test.db", "storage{$ds}api-test-history.json",
            "storage{$ds}app{$ds}database.sqlite",
            '.phpunit.cache', 'node_modules', '.github',
            "public{$ds}openapi.json", "public{$ds}postman_collection.json",
            "docs{$ds}openapi.json", "docs{$ds}postman",
        ];

        $dirIterator = new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS);
        $recursiveIterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);

        /** @var array<int, \SplFileInfo> $items */
        $items = iterator_to_array($recursiveIterator);

        foreach ($items as $pathname => $item) {
            $relative = substr((string) $pathname, strlen($src) + 1);
            $target = $dst . DIRECTORY_SEPARATOR . $relative;

            $skip = false;
            foreach ($exclude as $ex) {
                if (str_starts_with($relative, $ex)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } elseif ($item->isFile()) {
                $content = file_get_contents((string) $pathname);
                if ($content === false) continue;
                $content = str_replace(
                    ['Siro API Framework', 'sirosoft/api', 'SiroPHP', '../siro-core'],
                    [$projectName, $projectName, $projectName, ''],
                    $content
                );
                $content = str_replace('my-api', $projectName, $content);
                file_put_contents($target, $content);
                $count++;
            }
        }

        return $count;
    }
}
