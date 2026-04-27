<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeResourceCommand
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
            $this->write('Resource name is required. Example: php siro make:resource UserResource');
            return 1;
        }

        $class = $this->normalizeResourceClass($name);
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Resources/' . $class . '.php');
            return 0;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Resources/' . $class . '.php');

        return 0;
    }

    private function normalizeResourceClass(string $name): string
    {
        $base = $this->studly($name);

        return str_ends_with($base, 'Resource') ? $base : ($base . 'Resource');
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class {$class} extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->data['id'] ?? null,
            'name' => $this->data['name'] ?? null,
            'created_at' => $this->data['created_at'] ?? null,
        ];
    }
}

PHP;
    }
}
