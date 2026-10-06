<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeFactoryCommand implements \Siro\Core\Commands\CommandInterface {
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
        if (str_ends_with($name, 'Factory')) {
            $name = substr($name, 0, -7);
        }
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
use Siro\Core\Testing\Factory;
use Siro\Core\Testing\Faker;

/**
 * Factory for generating {$name} model instances.
 *
 * Usage:
 *   \$user = {$name}Factory::new()->create();
 *   \$users = {$name}Factory::new()->count(10)->create();
 *   \$admin = {$name}Factory::new()->with(['role' => 'admin'])->create();
 */
final class {$name}Factory extends Factory
{
    /** @var class-string */
    protected string \$model = {$modelClass}::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => Faker::vnFullName(),
            'email' => Faker::email(),
        ];
    }
}

PHP;
    }
}
