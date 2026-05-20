<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeRequestCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Request name is required. Example: php siro make:request StoreProductRequest');
            return 1;
        }

        $class = $this->studly($name);
        $suffix = 'Request';
        if (!str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Requests' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Requests/' . $class . '.php');
            return 0;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Requests/' . $class . '.php');
        $this->write('');
        $this->write('  Use in controller:');
        $this->write('    public function store(' . $class . ' $request): Response');
        $this->write('    {');
        $this->write('        $data = $request->validated();');
        $this->write('        // ...');
        $this->write('    }');

        return 0;
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Requests;

use Siro\Core\FormRequest;

final class {$class} extends FormRequest
{
    public function rules(): array
    {
        return [
            // 'name' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            // 'name.required' => 'Name is required',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
PHP;
    }
}
