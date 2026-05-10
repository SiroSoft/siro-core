<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeSeederCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Generate a database seeder class.
 *
 * Creates a seeder file in database/seeds/ with a run() method
 * for seeding initial data.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Seeder name is required. Example: php siro make:seeder UserSeeder');
            return 1;
        }

        $name = preg_replace('/[^a-zA-Z0-9_]+/', '', $name) ?? $name;
        if ($name === '') {
            $this->write('Invalid seeder name.');
            return 1;
        }

        $className = $this->studly($name);
        if (!str_ends_with($className, 'Seeder')) {
            $className .= 'Seeder';
        }

        $seedDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds';
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0775, true);
        }

        $path = $seedDir . DIRECTORY_SEPARATOR . $className . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: database/seeds/' . $className . '.php');
            return 0;
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

use Siro\Core\Database;

final class {$className}
{
    public function run(): void
    {
        // Example:
        // Database::table('users')->insert([
        //     'name' => 'Admin',
        //     'email' => 'admin@example.com',
        //     'password' => password_hash('password', PASSWORD_DEFAULT),
        //     'status' => 1,
        //     'created_at' => date('Y-m-d H:i:s'),
        // ]);
    }
}

PHP;

        file_put_contents($path, $content);
        $this->write('Generated: database/seeds/' . $className . '.php');

        return 0;
    }
}
