<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeMiddlewareCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Middleware name is required. Example: php siro make:middleware LogMiddleware');
            return 1;
        }

        $class = $this->studly($name);
        $suffix = 'Middleware';
        if (!str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Middleware' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Middleware/' . $class . '.php');
            return 0;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Middleware/' . $class . '.php');
        $this->write('');
        $this->write('  Register in routes/api.php:');
        $this->write('    Router::setMiddlewareAliases([\'log\' => ' . '\\App\\Middleware\\' . $class . '::class]);');
        $this->write('    $router->get(\'/path\', [Controller::class, \'method\'], [\'log\']);');

        return 0;
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Middleware;

use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

final class {$class} implements MiddlewareInterface
{
    public function handle(Request \$request, callable \$next): mixed
    {
        // Before request processing
        \$start = microtime(true);

        \$response = \$next(\$request);

        // After request processing
        \$duration = (microtime(true) - \$start) * 1000;

        if (\$response instanceof Response) {
            \$response->header('X-Middleware-Duration', (string) round(\$duration, 2));
        }

        return \$response;
    }
}
PHP;
    }
}
