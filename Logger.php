<?php

declare(strict_types=1);

namespace Siro\Core;

use Throwable;

final class Logger
{
    private static string $logDir = '';

    public static function boot(string $basePath): void
    {
        self::$logDir = rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0775, true);
        }

        // Ensure all log files exist
        $logFiles = ['request.log', 'error.log', 'slow.log'];
        foreach ($logFiles as $file) {
            $filePath = self::$logDir . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($filePath)) {
                file_put_contents($filePath, '');
            }
        }
    }

    public static function request(string $method, string $path, int $status, float $timeMs): void
    {
        self::write(
            'request.log',
            sprintf('[%s] %s %s %d %.2fms', date('Y-m-d H:i:s'), strtoupper($method), $path, $status, $timeMs)
        );
    }

    public static function slowRequest(string $method, string $path, int $status, float $timeMs): void
    {
        if ($timeMs <= 100.0) {
            return;
        }

        self::write(
            'slow.log',
            sprintf('[%s] %s %s %d %.2fms', date('Y-m-d H:i:s'), strtoupper($method), $path, $status, $timeMs)
        );
    }

    public static function error(Throwable|string $error): void
    {
        if ($error instanceof Throwable) {
            $message = self::sanitize((string) $error->getMessage());
            $line = sprintf(
                '[%s] %s: %s in %s:%d',
                date('Y-m-d H:i:s'),
                $error::class,
                $message,
                $error->getFile(),
                $error->getLine()
            );
        } else {
            $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), self::sanitize($error));
        }

        self::write('error.log', $line);
    }

    private static function write(string $fileName, string $line): void
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0775, true);
        }

        error_log($line . PHP_EOL, 3, self::$logDir . DIRECTORY_SEPARATOR . $fileName);
    }

    private static function sanitize(string $message): string
    {
        $patterns = [
            '/(authorization\s*[:=]\s*)([^\s,;]+)/i',
            '/(bearer\s+)([^\s,;]+)/i',
            '/(password\s*[:=]\s*)([^\s,;]+)/i',
            '/(token\s*[:=]\s*)([^\s,;]+)/i',
        ];

        $replacements = [
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1[REDACTED]',
        ];

        return (string) preg_replace($patterns, $replacements, $message);
    }
}
