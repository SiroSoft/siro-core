<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeControllerCommand
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
            $this->write('Controller name is required. Example: php siro make:controller UserController');
            return 1;
        }

        $class = $this->normalizeControllerClass($name);
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Controllers/' . $class . '.php');
            return 0;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Controllers/' . $class . '.php');

        return 0;
    }

    private function normalizeControllerClass(string $name): string
    {
        $base = $this->studly($name);

        return str_ends_with($base, 'Controller') ? $base : ($base . 'Controller');
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\Request;
use Siro\Core\Response;

final class {$class}
{
    public function index(Request $request): Response
    {
        return Response::success([], '{$class} index');
    }
}

PHP;
    }
}
