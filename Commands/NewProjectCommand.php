<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class NewProjectCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(string $basePath)
    {
        $execPath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->write('SiroPHP root: ' . $execPath);
    }

    /** @param array<int, string> $args */
    public function run(array $args): int {
        $name = trim((string)($args[0] ?? ''));
        if ($name === '') { $this->write('Usage: php siro new <project-name>'); return 1; }

        $this->write("Creating new SiroPHP project: {$name}");

        $targetDir = getcwd() . DIRECTORY_SEPARATOR . $name;
        if (is_dir($targetDir)) { $this->write("Directory already exists: {$name}"); return 1; }

        mkdir($targetDir, 0755, true);

        $cmd = sprintf('composer create-project sirosoft/api %s --no-interaction 2>&1', escapeshellarg($targetDir));
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) { $this->error("Failed to create project."); return $exitCode; }

        passthru("php " . escapeshellarg($targetDir . "/siro") . " key:generate 2>&1");

        copy($targetDir . '/.env.example', $targetDir . '/.env');

        $this->success("SiroPHP project '{$name}' created successfully!");
        $this->write("  cd {$name}");
        $this->write("  php siro serve");

        return 0;
    }
}
