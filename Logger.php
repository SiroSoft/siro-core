<?php

declare(strict_types=1);

namespace Siro\Core;

use Throwable;

/**
 * File-based logger with daily rotation and trace support.
 *
 * Logs requests, errors, slow queries, and per-request trace data.
 * Supports log retention, size-based rotation, credential sanitization,
 * and CLI trace lookup.
 *
 * @package Siro\Core
 */
final class Logger
{
    private static string $logDir = '';
    private static int $retentionDays = 30;
    private static int $slowThreshold = 100;
    private static int $maxFileSize = 50 * 1024 * 1024; // 50MB

    public static function boot(string $basePath): void
    {
        self::$logDir = rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0775, true);
        }

        self::$retentionDays = max(1, (int) Env::get('LOG_RETENTION_DAYS', '30'));
        self::$slowThreshold = max(0, (int) Env::get('DB_SLOW_QUERY_THRESHOLD', '100'));
    }

    public static function request(string $method, string $path, int $status, float $timeMs, string $ip = '', string $traceId = '', string $userAgent = ''): void
    {
        $parts = [
            date('Y-m-d H:i:s'),
            strtoupper($method),
            $path,
            $status,
            sprintf('%.2fms', $timeMs),
        ];

        if ($traceId !== '') {
            $parts[] = 'trace:' . $traceId;
        }
        if ($ip !== '') {
            $parts[] = 'ip:' . $ip;
        }
        if ($userAgent !== '') {
            $parts[] = 'ua:' . mb_substr($userAgent, 0, 60);
        }

        self::write('request', implode(' | ', $parts));
    }

    public static function slowRequest(string $method, string $path, int $status, float $timeMs): void
    {
        if ($timeMs <= self::$slowThreshold) {
            return;
        }

        $line = sprintf(
            '[%s] %s %s %d %.2fms (threshold: %dms)',
            date('Y-m-d H:i:s'), strtoupper($method), $path, $status, $timeMs, self::$slowThreshold
        );

        self::write('slow', $line, true);
    }

    public static function error(Throwable|string $error): void
    {
        if ($error instanceof Throwable) {
            $message = self::sanitize($error->getMessage());
            $line = sprintf(
                '[%s] %s: %s in %s:%d',
                date('Y-m-d H:i:s'),
                $error::class,
                $message,
                $error->getFile(),
                $error->getLine()
            );

            // Stack trace for fatal errors
            $trace = $error->getTraceAsString();
            if ($trace !== '') {
                $line .= PHP_EOL . '  Stack trace:' . PHP_EOL . '  ' . str_replace(PHP_EOL, PHP_EOL . '  ', $trace);
            }
        } else {
            $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), self::sanitize($error));
        }

        self::write('error', $line, true);
    }

    public static function trace(string $traceId, array $data): void
    {
        $data['timestamp'] = date('Y-m-d H:i:s');
        $data['trace_id'] = $traceId;
        self::writeTrace($traceId, $data);
    }

    private static function write(string $type, string $line, bool $alsoDaily = false): void
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0775, true);
        }

        // Daily log file: request-2026-04-29.log
        $dailyFile = self::$logDir . DIRECTORY_SEPARATOR . $type . '-' . date('Y-m-d') . '.log';
        $line = $line . PHP_EOL;

        // Always write to daily file
        error_log($line, 3, $dailyFile);

        // Also append to main file for quick tail
        if ($alsoDaily) {
            $mainFile = self::$logDir . DIRECTORY_SEPARATOR . $type . '.log';
            error_log($line, 3, $mainFile);
        }

        // Rotate if too large (only for main files)
        if ($alsoDaily && is_file($mainFile) && filesize($mainFile) > self::$maxFileSize) {
            $rotated = $mainFile . '.' . date('Y-m-d-Hi');
            @rename($mainFile, $rotated);
        }
    }

    private static function writeTrace(string $traceId, array $data): void
    {
        $traceDir = self::$logDir . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) {
            @mkdir($traceDir, 0775, true);
        }

        $traceFile = $traceDir . DIRECTORY_SEPARATOR . $traceId . '.json';
        file_put_contents($traceFile, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // Cleanup old traces daily (keep retention days)
        static $cleaned = false;
        if (!$cleaned) {
            $cleaned = true;
            self::cleanOldTraces();
        }
    }

    private static function cleanOldTraces(): void
    {
        $traceDir = self::$logDir . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) {
            return;
        }

        $cutoff = time() - (self::$retentionDays * 86400);
        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private static function sanitize(string $message): string
    {
        $patterns = [
            '/(authorization\s*[:=]\s*)([^\s,;]+)/i',
            '/(bearer\s+)([^\s,;]+)/i',
            '/(password\s*[:=]\s*)([^\s,;]+)/i',
            '/(token\s*[:=]\s*)([^\s,;]{8,})/i',
            '/(secret\s*[:=]\s*)([^\s,;]{8,})/i',
        ];

        $replacements = [
            '$1[REDACTED]', '$1[REDACTED]', '$1[REDACTED]', '$1[REDACTED]', '$1[REDACTED]',
        ];

        return (string) preg_replace($patterns, $replacements, $message);
    }
}
