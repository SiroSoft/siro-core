<?php

declare(strict_types=1);

namespace Siro\Core;

use Throwable;

final class Logger
{
    private static string $logDir = '';
    private static int $retentionDays = 30;
    private static int $slowThreshold = 100;
    private static int $maxFileSize = 50 * 1024 * 1024;

    /** @var array{headers: array<int, string>, body: array<int, string>, query: array<int, string>} */
    private static array $sanitizeConfig = [
        'headers' => ['authorization', 'cookie', 'x-api-key', 'x-csrf-token', 'session-id'],
        'body' => ['password', 'token', 'otp', 'secret', 'credit_card', 'credit-card', 'card_number', 'cvv', 'pin', 'ssn', 'passport'],
        'query' => ['token', 'key', 'secret', 'api_key', 'code'],
    ];

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

        // Create app.log if it doesn't exist
        $appLog = self::$logDir . DIRECTORY_SEPARATOR . 'app.log';
        if (!file_exists($appLog)) {
            @touch($appLog);
        }

        // Protect log directory from web access
        self::protectLogDir();
    }

    public static function reset(): void
    {
        self::$logDir = '';
        self::$retentionDays = 30;
        self::$slowThreshold = 100;
        self::$maxFileSize = 50 * 1024 * 1024;
    }

    /** @param array{headers?: array<int, string>, body?: array<int, string>, query?: array<int, string>} $config */
    public static function setSanitizeConfig(array $config): void
    {
        if (isset($config['headers'])) self::$sanitizeConfig['headers'] = $config['headers'];
        if (isset($config['body'])) self::$sanitizeConfig['body'] = $config['body'];
        if (isset($config['query'])) self::$sanitizeConfig['query'] = $config['query'];
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
            $parts[] = 'ua:' . self::escapeLog(mb_substr($userAgent, 0, 60));
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

            $trace = $error->getTraceAsString();
            if ($trace !== '') {
                $line .= PHP_EOL . '  Stack trace:' . PHP_EOL . '  ' . str_replace(PHP_EOL, PHP_EOL . '  ', self::sanitize($trace));
            }
        } else {
            $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), self::sanitize($error));
        }

        self::write('error', self::escapeLog($line), true);
    }

    /** @param array<string, mixed> $data */
    public static function trace(string $traceId, array $data): void
    {
        $data['timestamp'] = date('Y-m-d H:i:s');
        $data['trace_id'] = $traceId;

        // Sanitize request headers
        if (isset($data['request_headers']) && is_array($data['request_headers'])) {
            $data['request_headers'] = self::sanitizeHeaders($data['request_headers']);
        }

        // Sanitize request body
        if (isset($data['request_body']) && is_string($data['request_body'])) {
            $data['request_body'] = self::sanitizeJsonBody($data['request_body']);
        }

        // Sanitize response body
        if (isset($data['response_body']) && is_string($data['response_body'])) {
            $data['response_body'] = self::sanitizeJsonBody($data['response_body']);
        }

        // Sanitize query params
        if (isset($data['query_params']) && is_array($data['query_params'])) {
            foreach (self::$sanitizeConfig['query'] as $field) {
                if (isset($data['query_params'][$field])) {
                    $data['query_params'][$field] = '[REDACTED]';
                }
            }
        }

        self::writeTrace($traceId, $data);
    }

    public static function sanitize(string $message): string
    {
        // Escape log injection first
        $message = self::escapeLog($message);

        // Redact sensitive patterns in any context
        $sensitive = [
            '/(authorization\s*[:=]\s*)([^\s,;&]+)/i' => '$1[REDACTED]',
            '/(bearer\s+)([^\s,;&]{8,})/i' => '$1[REDACTED]',
            '/(password\s*[:=]\s*["\']?)([^\s,;&"\']{3,})/i' => '$1[REDACTED]',
            '/(\bpassword\b)([^"]*?)(\d{3,})/i' => '$1[REDACTED]',
            '/(token\s*[:=]\s*["\']?)([^\s,;&"\']{8,})/i' => '$1[REDACTED]',
            '/(secret\s*[:=]\s*["\']?)([^\s,;&"\']{4,})/i' => '$1[REDACTED]',
            '/(otp\s*[:=]\s*["\']?)([^\s,;&"\']{4,})/i' => '$1[REDACTED]',
            '/(credit_card|card_number|cvv|ssn|passport)\s*[:=]\s*["\']?([^\s,;&"\']{4,})/i' => '$1[REDACTED]',
            '/(\bapi[_-]?key\b\s*[:=]\s*["\']?)([^\s,;&"\']{4,})/i' => '$1[REDACTED]',
            '/(session[_-]?id\s*[:=]\s*["\']?)([^\s,;&"\']{4,})/i' => '$1[REDACTED]',
            '/\b\d{13,19}\b/' => '[REDACTED-CARD]', // Credit card number pattern
        ];

        return (string) preg_replace(array_keys($sensitive), array_values($sensitive), $message);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function sanitizeHeaders(array $headers): array
    {
        foreach ($headers as $key => $value) {
            $lk = strtolower((string) $key);
            if (in_array($lk, self::$sanitizeConfig['headers'], true)) {
                $headers[$key] = '[REDACTED]';
            }
        }
        return $headers;
    }

    public static function sanitizeJsonBody(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return $json;

        $decoded = self::sanitizeRecursive($decoded, self::$sanitizeConfig['body']);

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : $json;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $sensitiveFields
     * @return array<string, mixed>
     */
    private static function sanitizeRecursive(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => &$value) {
            $lk = strtolower((string) $key);
            if (is_array($value)) {
                $value = self::sanitizeRecursive($value, $sensitiveFields);
            } elseif (is_string($value)) {
                foreach ($sensitiveFields as $field) {
                    if ($lk === $field || str_contains($lk, $field)) {
                        $value = '[REDACTED]';
                        break;
                    }
                }
            }
        }
        return $data;
    }

    public static function escapeLog(string $message): string
    {
        // Prevent log injection by escaping newlines and control characters
        return str_replace(["\r\n", "\n", "\r"], '\n', $message);
    }

    public static function getLogDir(): string
    {
        return self::$logDir;
    }

    private static function write(string $type, string $line, bool $alsoDaily = false): void
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0775, true);
        }

        $dailyFile = self::$logDir . DIRECTORY_SEPARATOR . $type . '-' . date('Y-m-d') . '.log';
        $line = self::escapeLog($line) . PHP_EOL;

        error_log($line, 3, $dailyFile);

        if ($alsoDaily) {
            $mainFile = self::$logDir . DIRECTORY_SEPARATOR . $type . '.log';
            error_log($line, 3, $mainFile);
        }

        if ($alsoDaily && is_file($mainFile) && filesize($mainFile) > self::$maxFileSize) {
            $rotated = $mainFile . '.' . date('Y-m-d-Hi');
            @rename($mainFile, $rotated);
        }

        static $cleaned = false;
        if (!$cleaned) {
            $cleaned = true;
            self::cleanOldLogs();
        }
    }

    /** @param array<string, mixed> $data */
    private static function writeTrace(string $traceId, array $data): void
    {
        $traceDir = self::$logDir . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) {
            @mkdir($traceDir, 0775, true);
        }

        $traceFile = $traceDir . DIRECTORY_SEPARATOR . $traceId . '.json';
        file_put_contents($traceFile, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        static $cleaned = false;
        if (!$cleaned) {
            $cleaned = true;
            self::cleanOldTraces();
        }
    }

    private static function cleanOldTraces(): void
    {
        $traceDir = self::$logDir . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) return;

        $cutoff = time() - (self::$retentionDays * 86400);
        foreach (glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
    }

    private static function cleanOldLogs(): void
    {
        if (self::$logDir === '' || !is_dir(self::$logDir)) return;

        $cutoff = time() - (self::$retentionDays * 86400);

        foreach (glob(self::$logDir . DIRECTORY_SEPARATOR . '*-????-??-??.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
        foreach (glob(self::$logDir . DIRECTORY_SEPARATOR . '*.log.*') ?: [] as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
    }

    private static function protectLogDir(): void
    {
        $logDir = self::$logDir;
        $traceDir = $logDir . DIRECTORY_SEPARATOR . 'traces';

        // Apache: .htaccess
        foreach ([$logDir, $traceDir] as $dir) {
            if (!is_dir($dir)) continue;
            $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }

        // Nginx: deny.conf snippet (include this in your nginx config)
        $nginxConf = $logDir . DIRECTORY_SEPARATOR . 'nginx-deny.conf';
        if (!file_exists($nginxConf)) {
            file_put_contents($nginxConf, "deny all;\n");
        }

        // IIS: web.config
        foreach ([$logDir, $traceDir] as $dir) {
            if (!is_dir($dir)) continue;
            $webConfig = $dir . DIRECTORY_SEPARATOR . 'web.config';
            if (!file_exists($webConfig)) {
                file_put_contents($webConfig, '<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <security>
            <requestFiltering>
                <denyUrlSequences>
                    <add sequence="."/>
                </denyUrlSequences>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
');
            }
        }
    }
}
