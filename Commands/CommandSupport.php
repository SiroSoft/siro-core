<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Shared CLI helper methods for commands.
 *
 * Provides write(), ask(), table(), confirmOverwrite(), studly(),
 * and singular() utilities used by all *Command classes.
 *
 * @package Siro\Core\Commands
 */
trait CommandSupport
{
    protected function write(string $line): void
    {
        echo $line . PHP_EOL;
    }

    /** @param array<int, array<int, string>> $rows */
    protected function table(array $headers, array $rows): void
    {
        if ($headers === [] || $rows === []) {
            return;
        }

        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
            foreach ($rows as $row) {
                $len = strlen((string) ($row[$i] ?? ''));
                if ($len > $widths[$i]) {
                    $widths[$i] = $len;
                }
            }
        }

        // Header separator
        $separator = '+' . implode('+', array_map(fn (int $w): string => str_repeat('-', $w + 2), $widths)) . '+';

        // Print header
        $this->write($separator);
        $this->write('| ' . implode(' | ', array_map(
            fn (int $i, string $h): string => str_pad($h, $widths[$i]),
            array_keys($headers),
            $headers
        )) . ' |');
        $this->write($separator);

        // Print rows
        foreach ($rows as $row) {
            $this->write('| ' . implode(' | ', array_map(
                fn (int $i, string $val): string => str_pad((string) $val, $widths[$i]),
                array_keys($row),
                $row
            )) . ' |');
        }

        $this->write($separator);
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
