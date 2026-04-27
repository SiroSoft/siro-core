<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

trait CommandSupport
{
    protected function write(string $line): void
    {
        echo $line . PHP_EOL;
    }

    protected function ask(string $question): string
    {
        if (function_exists('readline')) {
            $value = readline($question);
            return is_string($value) ? trim($value) : '';
        }

        echo $question;
        $value = fgets(STDIN);
        return is_string($value) ? trim($value) : '';
    }

    protected function confirmOverwrite(string $basePath, string $path): bool
    {
        $relative = str_replace($basePath . DIRECTORY_SEPARATOR, '', $path);
        $answer = $this->ask('File ' . $relative . ' already exists. Overwrite? (y/N): ');
        return in_array(strtolower($answer), ['y', 'yes'], true);
    }

    protected function studly(string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', strtolower(trim($value)));
        return str_replace(' ', '', ucwords($normalized));
    }

    protected function singular(string $value): string
    {
        if (str_ends_with($value, 'ies')) {
            return substr($value, 0, -3) . 'y';
        }

        if (str_ends_with($value, 's') && strlen($value) > 1) {
            return substr($value, 0, -1);
        }

        return $value;
    }
}
