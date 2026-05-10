<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeFactoryCommand
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
            $this->write('Factory name is required. Example: php siro make:factory User');
            return 1;
        }

        $name = $this->studly($name);
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'factories';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $name . 'Factory.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: database/factories/' . $name . 'Factory.php');
            return 0;
        }

        file_put_contents($path, $this->template($name));
        $this->write('Generated: database/factories/' . $name . 'Factory.php');
        return 0;
    }

    private function template(string $name): string
    {
        $modelClass = "App\\Models\\{$name}";

        return <<<PHP
<?php

declare(strict_types=1);

namespace Database\Factories;

use {$modelClass};

/**
 * Factory for generating {$name} model instances.
 *
 * Usage:
 *   \$user = {$name}Factory::new()->create();
 *   \$users = {$name}Factory::new()->count(10)->create();
 */
final class {$name}Factory
{
    private int \$count = 1;
    /** @var array<string, mixed> */
    private array \$overrides = [];

    public static function new(): self
    {
        return new self();
    }

    public function count(int \$count): self
    {
        \$this->count = max(1, \$count);
        return \$this;
    }

    /** @param array<string, mixed> \$data */
    public function with(array \$data): self
    {
        \$this->overrides = \$data;
        return \$this;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }

    /** @return {$name}|array<int, {$name}> */
    public function create(): {$name}|array
    {
        if (\$this->count === 1) {
            return {$name}::create(array_merge(\$this->definition(), \$this->overrides));
        }

        \$results = [];
        for (\$i = 0; \$i < \$this->count; \$i++) {
            \$results[] = {$name}::create(array_merge(\$this->definition(), \$this->overrides));
        }
        return \$results;
    }
}

PHP;
    }
}
