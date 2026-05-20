<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeObserverCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Observer name is required. Example: php siro make:observer ProductObserver');
            return 1;
        }

        $class = $this->studly($name);
        $suffix = 'Observer';
        if (!str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Observers' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Observers/' . $class . '.php');
            return 0;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Observers/' . $class . '.php');
        $this->write('');
        $this->write('  Register in routes/api.php:');
        $this->write('    use App\\Models\\Product;');
        $this->write('    Product::observe(\\App\\Observers\\' . $class . '::class);');

        return 0;
    }

    private function template(string $class): string
    {
        $model = str_replace('Observer', '', $class);
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Observers;

use App\\Models\\{$model};
use Siro\\Core\\Observers\\ModelObserver;

final class {$class} extends ModelObserver
{
    public function creating({$model} \$model): void
    {
        // Before create
    }

    public function created({$model} \$model): void
    {
        // After create
    }

    public function updating({$model} \$model): void
    {
        // Before update
    }

    public function updated({$model} \$model): void
    {
        // After update
    }

    public function deleting({$model} \$model): void
    {
        // Before delete
    }

    public function deleted({$model} \$model): void
    {
        // After delete
    }
}
PHP;
    }
}
