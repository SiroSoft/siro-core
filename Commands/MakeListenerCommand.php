<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeListenerCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Listener name is required. Example: php siro make:listener SendWelcomeEmail');
            return 1;
        }

        $class = $this->studly($name);
        $suffix = 'Listener';
        if (!str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Listeners' . DIRECTORY_SEPARATOR . $class . '.php';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if (is_file($path)) {
            $this->write('Already exists: app/Listeners/' . $class . '.php');
            return 0;
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Listeners/' . $class . '.php');
        $this->write('');
        $this->write('  Register in routes/api.php:');
        $this->write('    Event::listen(\'user.registered\', \\App\\Listeners\\' . $class . '::class);');

        return 0;
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Listeners;

final class {$class}
{
    /**
     * Handle the event.
     *
     * @param array<string, mixed> \$data Event data payload
     */
    public function handle(array \$data = []): void
    {
        // Process the event
        // \$userId = \$data['user_id'] ?? null;
    }
}
PHP;
    }
}
