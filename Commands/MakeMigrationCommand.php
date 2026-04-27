<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeMigrationCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Migration name is required. Example: php siro make:migration create_users_table');
            return 1;
        }

        $normalized = $this->normalizeName($name);
        $timestamp = date('YmdHis');
        $filename = $timestamp . '_' . $normalized . '.php';

        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, $this->template($normalized));
        $this->write('Generated: database/migrations/' . $filename);

        return 0;
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? 'migration';
        $name = trim($name, '_');

        return $name !== '' ? $name : 'migration';
    }

    private function template(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function up(PDO \$db): void
    {
        // TODO: implement migration up
        // Example:
        // \$db->exec("CREATE TABLE users (id BIGINT PRIMARY KEY AUTO_INCREMENT)");
    }

    public function down(PDO \$db): void
    {
        // TODO: implement migration down
        // Example:
        // \$db->exec("DROP TABLE users");
    }
};

PHP;
    }
}
