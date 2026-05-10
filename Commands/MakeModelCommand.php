<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate a Model class.
 *
 * Creates a model file extending Siro\Core\Model with auto-detected
 * table name, casts, hidden, and fillable arrays.
 *
 * @package Siro\Core\Commands
 */
final class MakeModelCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Model name is required. Example: php siro make:model User');
            return 1;
        }

        // Convert to StudlyCase
        $name = $this->studly($name);
        
        $modelPath = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $name . '.php';

        if (!is_dir(dirname($modelPath))) {
            mkdir(dirname($modelPath), 0775, true);
        }

        if (is_file($modelPath)) {
            if (!$this->confirmOverwrite($this->basePath, $modelPath)) {
                $this->write('Skipped: app/Models/' . $name . '.php');
                return 0;
            }
        }

        file_put_contents($modelPath, $this->modelTemplate($name));
        $this->write('Generated: app/Models/' . $name . '.php');

        return 0;
    }

    private function modelTemplate(string $name): string
    {
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class {$name} extends Model
{
    protected string \$table = '{$table}';

    /** @var array<int, string> */
    protected array \$hidden = [];

    /** @var array<string, string> */
    protected array \$casts = [
        'id' => 'int',
    ];

    /** @var array<int, string> */
    protected array \$fillable = [];
}

PHP;
    }
}
