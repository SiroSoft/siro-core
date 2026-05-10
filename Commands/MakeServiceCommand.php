<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeServiceCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Usage: php siro make:service <name>');
            return 1;
        }

        $class = ucfirst($this->studly($name));
        if (!str_ends_with($class, 'Service')) {
            $class .= 'Service';
        }

        $path = $this->basePath . '/app/Services/' . $class . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            return 1;
        }

        $model = str_replace('Service', '', $class);
        $repoClass = $model . 'Repository';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $repoPath = $this->basePath . '/app/Repositories/' . $repoClass . '.php';
        $hasRepo = is_file($repoPath);

        if ($hasRepo) {
            file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\\{$repoClass};

final class {$class}
{
    public function __construct(private readonly {$repoClass} \$repo)
    {
    }

    public function getAll(int \$page = 1, int \$perPage = 20): array
    {
        return \$this->repo->findAll(\$page, \$perPage);
    }

    public function getById(int \$id): mixed
    {
        return \$this->repo->findById(\$id);
    }

    public function create(array \$data): mixed
    {
        return \$this->repo->store(\$data);
    }

    public function update(int \$id, array \$data): mixed
    {
        return \$this->repo->update(\$id, \$data);
    }

    public function delete(int \$id): bool
    {
        return \$this->repo->destroy(\$id);
    }
}

PHP);
        } else {
            file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\\{$model};

final class {$class}
{
    public function __construct(private readonly {$model} \$model)
    {
    }

    public function getAll(int \$page = 1, int \$perPage = 20): array
    {
        return {$model}::query()->orderBy('id', 'DESC')->paginate(\$perPage, \$page);
    }

    public function getById(int \$id): mixed
    {
        return {$model}::find(\$id);
    }

    public function create(array \$data): mixed
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

    public function delete(int \$id): bool
    {
        \$item = {$model}::find(\$id);
        return \$item !== null && (bool) \$item->delete();
    }
}

PHP);
        }
        $this->write("Generated: app/Services/{$class}.php");
        return 0;
    }
}
