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
    private static ?bool $supportsColor = null;

    private function hasColor(): bool
    {
        if (self::$supportsColor !== null) return self::$supportsColor;
        // Detect if terminal supports ANSI color
        $rawTerm = $_SERVER['TERM'] ?? '';
        $term = is_string($rawTerm) ? $rawTerm : '';
        $wtSession = getenv('WT_SESSION');
        if (PHP_OS_FAMILY === 'Windows') {
            self::$supportsColor = str_contains($term, 'xterm') || (is_string($wtSession) && $wtSession !== '');
        } else {
            self::$supportsColor = $term !== '' && $term !== 'dumb';
        }
        return self::$supportsColor;
    }

    private function colorize(string $text, string $color): string
    {
        if (!$this->hasColor()) return $text;
        $colors = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'magenta' => "\033[35m",
            'cyan' => "\033[36m",
            'bold' => "\033[1m",
            'reset' => "\033[0m",
        ];
        $code = $colors[$color] ?? '';
        return $code . $text . $colors['reset'];
    }

    protected function write(string $line): void
    {
        echo $line . PHP_EOL;
    }

    protected function info(string $message): void
    {
        $this->write('  ' . $this->colorize($message, 'blue'));
    }

    protected function success(string $message): void
    {
        $this->write($this->colorize('✓ ', 'green') . $this->colorize($message, 'green'));
    }

    protected function error(string $message): void
    {
        $this->write($this->colorize('✗ ', 'red') . $this->colorize($message, 'red'));
    }

    protected function warn(string $message): void
    {
        $this->write($this->colorize('⚠ ', 'yellow') . $this->colorize($message, 'yellow'));
    }

    protected function highlight(string $message): void
    {
        $this->write($this->colorize($message, 'bold'));
    }

    protected function comment(string $message): void
    {
        $this->write('# ' . $message);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    protected function table(array $headers, array $rows): void
    {
        if ($headers === [] || $rows === []) {
            return;
        }

        /** @var array<int, int> $widths */
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
        // Strip characters that are invalid in PHP identifiers (keeps ASCII letters,
        // digits, - and _ as word separators; removes everything else incl. Unicode).
        $value = preg_replace('/[^A-Za-z0-9\-_ ]+/', '', $value) ?? '';
        $value = str_replace(['-', '_'], ' ', trim($value));
        $words = explode(' ', $value);
        $words = array_map(fn(string $w): string => $w === '' ? '' : ucfirst($w), $words);
        return implode('', $words);
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

    protected function plural(string $value): string
    {
        // If already ends with 's' (likely already plural), keep as-is
        if (str_ends_with($value, 's')) {
            return $value;
        }

        // Words ending in 'sh', 'ch', 'x', 'z', 'ss' need 'es'
        if (str_ends_with($value, 'sh') || str_ends_with($value, 'ch') || 
            str_ends_with($value, 'x') || str_ends_with($value, 'z') ||
            str_ends_with($value, 'ss')) {
            return $value . 'es';
        }

        // Words ending in consonant + 'y' -> 'ies'
        if (str_ends_with($value, 'y')) {
            return substr($value, 0, -1) . 'ies';
        }

        // Default: add 's'
        return $value . 's';
    }

    /**
     * Safely convert a mixed value to string (satisfies PHPStan level=max).
     * @param mixed $value
     */
    protected function safeStr(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Recursively find all trace JSON files in the traces directory.
     * Handles nested structure: traces/YYYY/MM/DD/{hash_prefix}/*.json
     *
     * @return array<int, string>
     */
    protected function findTraceFiles(string $tracesDir): array
    {
        if (!is_dir($tracesDir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tracesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'json') {
                $files[] = $entry->getPathname();
            }
        }
        return $files;
    }

    /**
     * Find the N most-recent trace files without materializing the full list.
     * Keeps memory bounded and avoids sorting thousands of entries for "latest".
     *
     * @return array<int, string> newest-first trace file paths (max $limit entries)
     */
    protected function findRecentTraceFiles(string $tracesDir, int $limit): array
    {
        if (!is_dir($tracesDir) || $limit <= 0) {
            return [];
        }
        /** @var array<string, int> $files path => mtime */
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tracesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile() || $entry->getExtension() !== 'json') {
                continue;
            }
            $path = $entry->getPathname();
            $mtime = $entry->getMTime();
            if ($mtime === false) {
                $mtime = 0;
            }
            if (count($files) < $limit) {
                $files[$path] = $mtime;
                continue;
            }
            // Replace the oldest kept entry if this one is newer.
            $oldestPath = null;
            $minMtime = PHP_INT_MAX;
            foreach ($files as $p => $mt) {
                if ($mt < $minMtime) {
                    $minMtime = $mt;
                    $oldestPath = $p;
                }
            }
            if ($oldestPath !== null && $mtime > $minMtime) {
                unset($files[$oldestPath]);
                $files[$path] = $mtime;
            }
        }
        arsort($files);
        return array_keys($files);
    }

    /**
     * Find a trace file by trace ID in the nested traces directory.
     *
     * @return string|null Absolute path to the trace file, or null if not found.
     */
    protected function findTraceById(string $tracesDir, string $traceId): ?string
    {
        $candidates = [
            $tracesDir . DIRECTORY_SEPARATOR . $traceId . '.json',
            $tracesDir . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d') . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $traceId . '.json',
        ];
        foreach ($candidates as $pattern) {
            if (str_contains($pattern, '*')) {
                $matches = glob($pattern) ?: [];
                if ($matches !== []) {
                    return $matches[0];
                }
            } elseif (is_file($pattern)) {
                return $pattern;
            }
        }
        // Full recursive search as fallback
        foreach ($this->findTraceFiles($tracesDir) as $file) {
            if (basename($file, '.json') === $traceId) {
                return $file;
            }
        }
        return null;
    }

    protected function getTracesDir(string $basePath): string
    {
        return $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
    }
}
