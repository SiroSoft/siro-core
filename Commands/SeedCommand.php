<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class SeedCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $class = trim((string) ($args[0] ?? ''));
        $seedDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds';

        if ($class !== '') {
            return $this->runSingle($seedDir, $class);
        }

        return $this->runAll($seedDir);
    }

    private function runSingle(string $seedDir, string $class): int
    {
        $path = $seedDir . DIRECTORY_SEPARATOR . $class . '.php';

        if (!is_file($path)) {
            $this->write('Seeder not found: ' . $class);
            return 1;
        }

        require $path;

        if (!class_exists($class)) {
            $this->write('Seeder class not found: ' . $class);
            return 1;
        }

        $seeder = new $class();

        if (!method_exists($seeder, 'run')) {
            $this->write('Seeder must have a run() method: ' . $class);
            return 1;
        }

        $this->write('Seeding: ' . $class);
        $seeder->run();
        $this->write('Seeded: ' . $class);

        return 0;
    }

    private function runAll(string $seedDir): int
    {
        if (!is_dir($seedDir)) {
            $this->write('No seeders found in database/seeds/');
            return 0;
        }

        $files = glob($seedDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        if ($files === []) {
            $this->write('No seeders found in database/seeds/');
            return 0;
        }

        $this->write('Running seeders...');

        foreach ($files as $file) {
            $class = basename($file, '.php');
            require $file;

            if (!class_exists($class) || !method_exists($class, 'run')) {
                $this->write('Skipped invalid seeder: ' . $class);
                continue;
            }

            $this->write('Seeding: ' . $class);
            $seeder = new $class();
            $seeder->run();
        }

        $this->write('Seeding completed.');
        return 0;
    }
}
