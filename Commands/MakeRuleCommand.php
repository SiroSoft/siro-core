<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeRuleCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Rule name is required. Example: php siro make:rule Uppercase');
            return 1;
        }

        $class = $this->studly($name);
        $suffix = 'Rule';
        if (!str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Rules' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Rules/' . $class . '.php');
            return 0;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Rules/' . $class . '.php');
        $this->write('');
        $this->write('  Register in app/routes/api.php:');
        $this->write("    Validator::extend('" . $this->snake(str_replace('Rule', '', $class)) . "', ...);");

        return 0;
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Rules;

use Siro\\Core\\Validator;

final class {$class}
{
    /**
     * Validate the given value.
     *
     * @param string \$field
     * @param mixed \$value
     * @param array<int, string> \$params
     * @return bool
     */
    public function validate(string \$field, mixed \$value, array \$params = []): bool
    {
        // Example: return preg_match('/^[A-Z]+$/', (string) \$value) === 1;
        return true;
    }

    /**
     * Get the validation error message.
     *
     * @param string \$field
     * @param array<int, string> \$params
     * @return string
     */
    public function message(string \$field, array \$params = []): string
    {
        return \$field . ' is invalid.';
    }
}
PHP;
    }

    private function snake(string $value): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value);
    }
}
