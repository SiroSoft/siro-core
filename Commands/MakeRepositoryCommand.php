<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeRepositoryCommand
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
            $this->write('Usage: php siro make:repository <name>');
            return 1;
        }

        $class = ucfirst($this->studly($name));
        if (!str_ends_with($class, 'Repository')) {
            $class .= 'Repository';
        }

        $path = $this->basePath . '/app/Repositories/' . $class . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $model = str_replace('Repository', '', $class);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\\{$model};

final class {$class}
{
    public function findAll(int \$page = 1, int \$perPage = 20): array
    {
        return {$model}::query()->orderBy('id', 'DESC')->paginate(\$perPage, \$page);
    }

    public function findById(int \$id): mixed
    {
        return {$model}::find(\$id);
    }

    public function store(array \$data): mixed
    {
        return {$model}::create(\$data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    public function update(int \$id, array \$data): mixed
    {
        \$item = {$model}::find(\$id);
        if (\$item === null) return null;
        \$item->update(\$data);
        return \$item;
    }

    public function destroy(int \$id): bool
    {
        \$item = {$model}::find(\$id);
        if (\$item === null) return false;
        return (bool) \$item->delete();
    }
}

PHP);
        $this->write("Generated: app/Repositories/{$class}.php");
        return 0;
    }
}
