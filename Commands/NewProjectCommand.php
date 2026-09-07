<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class NewProjectCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(string $basePath)
    {
        unset($basePath);
    }

    /**
     * Resolve the filesystem target for a project name or path.
     *
     * Absolute paths are used as-is; bare names are anchored to the current
     * working directory. (Extracted for unit testing — see the D1 dogfood
     * report: `siro new /tmp/app` previously created ./tmp/app instead.)
     */
    public static function resolveTarget(string $name): string
    {
        $isAbsolute = DIRECTORY_SEPARATOR === '/'
            ? str_starts_with($name, '/')
            : (bool) preg_match('#^[A-Za-z]:[/\\\\]|^[/\\\\]#', $name);

        if ($isAbsolute) {
            return $name;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);

        return getcwd() . DIRECTORY_SEPARATOR . $normalized;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int {
        $name = trim((string)($args[0] ?? ''));
        if ($name === '') { $this->write('Usage: php siro new <project-name-or-path>'); return 1; }

        $this->write("Creating new SiroPHP project: {$name}");

        $targetDir = self::resolveTarget($name);
        if (is_dir($targetDir)) { $this->write("Directory already exists: {$targetDir}"); return 1; }

        $cmd = sprintf('composer create-project sirosoft/api %s --no-interaction 2>&1', escapeshellarg($targetDir));
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) { $this->error("Failed to create project."); return $exitCode; }

        chdir($targetDir);
        passthru("php siro key:generate 2>&1");

        $envExample = getcwd() . '/.env.example';
        $envFile = getcwd() . '/.env';
        if (file_exists($envExample) && !file_exists($envFile)) {
            copy($envExample, $envFile);
        }

        $this->success("SiroPHP project '{$name}' created successfully!");
        $this->write('  cd ' . ($targetDir === $name ? $name : $targetDir));
        $this->write("  php siro serve");

        return 0;
    }
}
