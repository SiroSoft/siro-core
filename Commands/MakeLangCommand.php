<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate a language translation file.
 *
 * Creates a PHP array file in storage/lang/{locale}/{name}.php
 * with example translation entries.
 *
 * Usage:
 *   php siro make:lang vi messages     # storage/lang/vi/messages.php
 *   php siro make:lang en validation   # storage/lang/en/validation.php
 *
 * @package Siro\Core\Commands
 */
final class MakeLangCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $locale = trim((string) ($args[0] ?? ''));
        $name = trim((string) ($args[1] ?? 'messages'));

        if ($locale === '') {
            $this->write('Usage: php siro make:lang <locale> [filename]');
            $this->write('  php siro make:lang vi messages');
            $this->write('  php siro make:lang en validation');
            return 1;
        }

        $langDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'lang'
            . DIRECTORY_SEPARATOR . $locale;

        if (!is_dir($langDir)) {
            mkdir($langDir, 0775, true);
        }

        $path = $langDir . DIRECTORY_SEPARATOR . $name . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write("Skipped: storage/lang/{$locale}/{$name}.php");
            return 0;
        }

        $examples = match ($name) {
            'validation' => $this->validationTemplate($locale),
            'messages' => $this->messagesTemplate($locale),
            default => $this->defaultTemplate($name),
        };

        file_put_contents($path, $examples);
        $this->write("Generated: storage/lang/{$locale}/{$name}.php");
        return 0;
    }

    private function validationTemplate(string $locale): string
    {
        $comment = $locale === 'vi'
            ? 'Thông báo lỗi xác thực'
            : 'Validation error messages';

        return <<<PHP
<?php

/**
 * {$comment}
 */

return [
    'required' => ':field is required',
    'email'    => ':field must be a valid email',
    'numeric'  => ':field must be numeric',
    'integer'  => ':field must be an integer',
    'min'      => ':field must be at least :min',
    'max'      => ':field must not exceed :max',
    'unique'   => ':field already exists',
    'exists'   => ':field does not exist',
    'confirmed' => ':field confirmation does not match',
    'in'       => ':field must be one of: :values',
];

PHP;
    }

    private function messagesTemplate(string $locale): string
    {
        return <<<PHP
<?php

/**
 * Application messages
 */

return [
    'welcome'     => 'Welcome',
    'goodbye'     => 'Goodbye',
    'not_found'   => 'Not found',
    'server_error' => 'Internal server error',
];

PHP;
    }

    private function defaultTemplate(string $name): string
    {
        return <<<PHP
<?php

return [
    // Add your translations here
    'key' => 'value',
];

PHP;
    }
}
