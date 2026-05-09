<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

/**
 * Automated deployment script.
 *
 * Reads deploy config from deploy.json or environment variables.
 * Supports git-based deployment and rsync.
 *
 * Usage:
 *   php siro deploy                    # Deploy using deploy.json config
 *   php siro deploy staging            # Deploy to staging environment
 *   php siro deploy production         # Deploy to production
 *   php siro deploy --init             # Create deploy.json template
 *   php siro deploy --list             # List configured environments
 *
 * @package Siro\Core\Commands
 */
final class DeployCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        if (in_array('--init', $args, true)) {
            return $this->initConfig();
        }

        if (in_array('--list', $args, true)) {
            return $this->listEnvironments();
        }

        $environment = trim((string) ($args[0] ?? ''));
        $config = $this->loadConfig();
        $environments = $config['environments'] ?? [];

        if ($environment === '') {
            // Deploy default environment
            $environment = $config['default'] ?? 'production';
        }

        if (!isset($environments[$environment])) {
            $this->write("Environment '{$environment}' not found in deploy.json.");
            $this->write('Available: ' . implode(', ', array_keys($environments)));
            return 1;
        }

        $env = $environments[$environment];
        $this->deploy($environment, $env);

        return 0;
    }

    /** @param array<string, mixed> $env */
    private function deploy(string $name, array $env): void
    {
        $method = $env['method'] ?? 'git';
        $branch = $env['branch'] ?? 'main';
        $remote = $env['remote'] ?? 'origin';

        $this->write("  \033[1;33mDeploying to '{$name}'...\033[0m");
        $this->write("  Method: {$method}");
        $this->write("  Branch: {$branch}");
        $this->write('');

        $start = microtime(true);

        match ($method) {
            'git' => $this->deployGit($remote, $branch, $env),
            'rsync' => $this->deployRsync($env),
            'custom' => $this->deployCustom($env),
            default => $this->write("Unknown deploy method: {$method}"),
        };

        $elapsed = microtime(true) - $start;
        $this->write("  \033[32mDeploy completed in " . number_format($elapsed, 2) . "s\033[0m");
    }

    /** @param array<string, mixed> $env */
    private function deployGit(string $remote, string $branch, array $env): void
    {
        $postDeploy = $env['post_deploy'] ?? [];
        $repoDir = $env['repo_dir'] ?? $this->basePath;
        $safeRemote = escapeshellarg($remote);
        $safeBranch = escapeshellarg($branch);
        $safeRepoDir = escapeshellarg($repoDir);

        $this->write('  Step 1: Pushing to remote...');
        passthru("cd {$safeRepoDir} && git push {$safeRemote} {$safeBranch} 2>&1", $code1);

        $this->write('  Step 2: Running post-deploy commands...');
        foreach ($postDeploy as $cmd) {
            $this->write("    Running: {$cmd}");
            passthru("cd {$safeRepoDir} && {$cmd} 2>&1", $code2);
        }

        $this->write("  \033[32mDone\033[0m");
    }

    /** @param array<string, mixed> $env */
    private function deployRsync(array $env): void
    {
        $host = $env['host'] ?? '';
        $user = $env['user'] ?? '';
        $target = $env['target'] ?? '';
        $exclude = $env['exclude'] ?? ['.env', 'vendor/', 'storage/logs/*', '.git/'];

        if ($host === '' || $target === '') {
            $this->write('  Error: host and target required for rsync deploy.');
            return;
        }

        $safeHost = escapeshellarg($host);
        $safeUser = escapeshellarg($user);
        $safeTarget = escapeshellarg($target);
        $safeBasePath = escapeshellarg($this->basePath);

        $excludeArgs = '';
        foreach ($exclude as $pattern) {
            $excludeArgs .= " --exclude=" . escapeshellarg($pattern);
        }

        $this->write("  Syncing to {$user}@{$host}:{$target}...");
        passthru("rsync -avz{$excludeArgs} {$safeBasePath}/ {$safeUser}@{$safeHost}:{$safeTarget} 2>&1", $code);
    }

    /** @param array<string, mixed> $env */
    private function deployCustom(array $env): void
    {
        $script = $env['script'] ?? '';
        if ($script === '') {
            $this->write('  Error: no custom script specified.');
            return;
        }

        $this->write("  Running custom script: {$script}");
        $safeBasePath = escapeshellarg($this->basePath);
        passthru("cd {$safeBasePath} && {$script} 2>&1", $code);
    }

    private function loadConfig(): array
    {
        $configFile = $this->basePath . DIRECTORY_SEPARATOR . 'deploy.json';

        if (is_file($configFile)) {
            $content = (string) file_get_contents($configFile);
            $decoded = json_decode($content, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function initConfig(): int
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'deploy.json';

        if (is_file($path)) {
            $this->write('deploy.json already exists.');
            if (!$this->confirmOverwrite($this->basePath, $path)) {
                return 0;
            }
        }

        $template = <<<JSON
{
    "default": "staging",
    "environments": {
        "staging": {
            "method": "git",
            "remote": "origin",
            "branch": "staging",
            "post_deploy": [
                "composer install --no-dev --optimize-autoloader",
                "php siro migrate",
                "php siro config:cache"
            ]
        },
        "production": {
            "method": "git",
            "remote": "origin",
            "branch": "main",
            "post_deploy": [
                "composer install --no-dev --optimize-autoloader",
                "php siro migrate",
                "php siro config:cache",
                "php siro optimize"
            ]
        }
    }
}
JSON;

        file_put_contents($path, $template . PHP_EOL);
        $this->write('Generated: deploy.json');
        $this->write('Edit this file with your server details before deploying.');
        return 0;
    }

    private function listEnvironments(): int
    {
        $config = $this->loadConfig();
        $environments = $config['environments'] ?? [];

        if ($environments === []) {
            $this->write('No environments configured. Run: php siro deploy --init');
            return 0;
        }

        $this->write('Configured environments:');
        $this->write('');

        $headers = ['Environment', 'Method', 'Branch/Remote'];
        $rows = [];
        foreach ($environments as $name => $env) {
            $rows[] = [
                $name,
                $env['method'] ?? 'git',
                ($env['branch'] ?? 'main') . ' / ' . ($env['remote'] ?? 'origin'),
            ];
        }

        $this->table($headers, $rows);
        return 0;
    }
}
