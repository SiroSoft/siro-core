<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

final class SeedCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    private function boot(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $this->basePath);
        }
        (new \Siro\Core\App($this->basePath))->boot();
    }

    /**
 * Run database seeders.
 *
 * Executes all seeder classes in the configured seed directories
 * (SEED_PATH, default database/seeds/), or a specific seeder if
 * a class name is provided. Seeders may live in a namespace
 * (e.g. Database\Seeders\UserSeeder) — pass the fully qualified
 * class name or the short class name.
 *
 * Configure additional seed directories with SEED_PATH, a
 * comma-separated list of paths relative to the app root or
 * absolute, e.g. SEED_PATH=database/seeders. Migration path for
 * apps with namespaced seeders: set SEED_PATH to the namespaced
 * directory and delete the one-line bridge subclasses.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $this->boot();

        $class = trim((string) ($args[0] ?? ''));

        if ($class !== '') {
            return $this->runSingle($class);
        }

        return $this->runAll();
    }

    private function runSingle(string $class): int
    {
        $seedDirs = $this->seedDirs();
        $index = $this->seederIndex($this->discoverSeeders($seedDirs));
        $resolved = $index[$class] ?? $index[$this->shortName($class)] ?? null;

        if ($resolved === null && class_exists($class) && method_exists($class, 'run')) {
            $resolved = $class;
        }

        if ($resolved === null) {
            // Legacy fallback: <seedDir>/<Class>.php in the default dir.
            $legacyPath = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds' . DIRECTORY_SEPARATOR . $class . '.php';
            if (is_file($legacyPath)) {
                require_once $legacyPath;
                if (!class_exists($class)) {
                    $this->write('Seeder class not found: ' . $class);
                    return 1;
                }
                $resolved = $class;
            }
        }

        if ($resolved === null) {
            $this->write('Seeder not found: ' . $class);
            return 1;
        }

        if (!method_exists($resolved, 'run')) {
            $this->write('Seeder must have a run() method: ' . $class);
            return 1;
        }

        $this->write('Seeding: ' . $resolved);
        $this->runSeeder($resolved);
        $this->write('Seeded: ' . $resolved);

        return 0;
    }

    private function runAll(): int
    {
        $seedDirs = $this->seedDirs();
        $existingDirs = array_values(array_filter($seedDirs, 'is_dir'));
        if ($existingDirs === []) {
            $this->write('No seeders found in database/seeds/');
            return 0;
        }

        $discovered = $this->discoverSeeders($existingDirs);
        $index = $this->seederIndex($discovered);

        // Check for DatabaseSeeder with ordered calls
        $dbSeederClass = $index['DatabaseSeeder'] ?? null;
        if ($dbSeederClass !== null) {
            $dbSeeder = new $dbSeederClass();
            if (property_exists($dbSeeder, 'calls') && is_array($dbSeeder->calls)) {
                $this->write('Running seeders (ordered)...');
                foreach ($dbSeeder->calls as $class) {
                    if (!is_string($class)) {
                        continue;
                    }
                    $resolved = $index[$class] ?? $index[$this->shortName($class)] ?? null;
                    if ($resolved === null && class_exists($class) && method_exists($class, 'run')) {
                        $resolved = $class;
                    }
                    if ($resolved === null) {
                        $this->write('Seeder not found: ' . $class);
                        continue;
                    }
                    $this->write('Seeding: ' . $resolved);
                    try {
                        $this->runSeeder($resolved);
                    } catch (\Throwable $e) {
                        $this->write('  ' . $this->colorize('✗ Seeder failed: ' . $resolved, 'red'));
                        $this->write('  ' . $this->colorize($e->getMessage(), 'red'));
                        return 1;
                    }
                }
                $this->write('Seeding completed.');
                return 0;
            }
        }

        // Fallback: run all seeder files in order.
        if ($discovered === []) {
            $this->write('No seeders found in database/seeds/');
            return 0;
        }

        $this->write('Running seeders...');

        $count = 0;
        foreach ($discovered as $entry) {
            if ($entry['class'] === $dbSeederClass) {
                continue; // DatabaseSeeder itself is only an orchestrator.
            }
            $this->write('Seeding: ' . $entry['class']);
            $this->runSeeder($entry['class']);
            $count++;
        }

        $this->write('Seeding completed. Ran ' . $count . ' seeder(s).');
        return 0;
    }

    /**
     * Seed directories from SEED_PATH (comma-separated, relative to
     * the app root or absolute). Defaults to database/seeds/.
     *
     * @return array<int, string>
     */
    private function seedDirs(): array
    {
        $raw = Env::get('SEED_PATH');
        if ($raw === null || trim($raw) === '') {
            return [$this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds'];
        }

        $dirs = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!str_starts_with($part, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $part)) {
                $part = $this->basePath . DIRECTORY_SEPARATOR . $part;
            }
            $dirs[] = $part;
        }

        return $dirs !== [] ? $dirs : [$this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds'];
    }

    /**
     * Discover seeder classes in the given directories.
     *
     * Recursively requires every *.php file once and records the
     * concrete classes it declares that have a run() method.
     *
     * @param array<int, string> $seedDirs
     * @return array<int, array{class: class-string, file: string}> In file order.
     */
    private function discoverSeeders(array $seedDirs): array
    {
        $files = $this->collectSeederFiles($seedDirs);
        $discovered = [];

        foreach ($files as $file) {
            $before = get_declared_classes();
            require_once $file;
            $after = get_declared_classes();
            foreach (array_diff($after, $before) as $class) {
                // DatabaseSeeder is an orchestrator: it may only carry
                // the $calls list and no run() method, but it still
                // needs to be discoverable for the ordered path.
                if (!method_exists($class, 'run') && $this->shortName($class) !== 'DatabaseSeeder') {
                    continue;
                }
                $ref = new \ReflectionClass($class);
                if ($ref->isAbstract()) {
                    continue;
                }
                $discovered[] = ['class' => $class, 'file' => $file];
            }
        }

        return $discovered;
    }

    /**
     * @param array<int, string> $seedDirs
     * @return array<int, string> Sorted *.php files, recursive.
     */
    private function collectSeederFiles(array $seedDirs): array
    {
        $files = [];
        foreach ($seedDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                if (!$info instanceof \SplFileInfo) {
                    continue;
                }
                if ($info->isFile() && strtolower($info->getExtension()) === 'php') {
                    $files[] = $info->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Index discovered seeders by fully qualified class name and by
     * short class name (first class wins on a short-name collision).
     *
     * @param array<int, array{class: class-string, file: string}> $discovered
     * @return array<string, class-string>
     */
    private function seederIndex(array $discovered): array
    {
        $index = [];
        foreach ($discovered as $entry) {
            $class = $entry['class'];
            $index[$class] = $class;
            $short = $this->shortName($class);
            if (!isset($index[$short])) {
                $index[$short] = $class;
            }
        }

        return $index;
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Instantiate and run a seeder class.
     *
     * @param class-string $class
     */
    private function runSeeder(string $class): void
    {
        $seeder = new $class();
        if (!method_exists($seeder, 'run')) {
            throw new \RuntimeException('Seeder must have a run() method: ' . $class);
        }
        /** @var object{run(): void} $seeder */
        $seeder->run();
    }
}
