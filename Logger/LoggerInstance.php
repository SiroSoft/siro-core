<?php

declare(strict_types=1);

namespace Siro\Core\Logger;

use Siro\Core\Cache;
use Siro\Core\Env;
use Throwable;

final class LoggerInstance implements LoggerInterface
{
    private string $logDir = '';
    private int $retentionDays = 30;
    private int $slowThreshold = 100;
    private int $maxFileSize = 50 * 1024 * 1024;

    /** @var array{headers: array<int, string>, body: array<int, string>, query: array<int, string>} */
    private array $sanitizeConfig = [
        'headers' => ['authorization', 'cookie', 'x-api-key', 'x-csrf-token', 'session-id'],
        'body' => ['password', 'token', 'otp', 'secret', 'credit_card', 'credit-card', 'card_number', 'cvv', 'pin', 'ssn', 'passport'],
        'query' => ['token', 'key', 'secret', 'api_key', 'code'],
    ];

    public function boot(string $basePath): void
    {
        $this->logDir = rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }

        $this->retentionDays = max(1, (int) Env::get('LOG_RETENTION_DAYS', '30'));
        $this->slowThreshold = max(0, (int) Env::get('DB_SLOW_QUERY_THRESHOLD', '100'));

        $appLog = $this->logDir . DIRECTORY_SEPARATOR . 'app.log';
        if (!file_exists($appLog)) {
            touch($appLog);
        }

        $this->protectLogDir();
    }

    public function reset(): void
    {
        $this->logDir = '';
        $this->retentionDays = 30;
        $this->slowThreshold = 100;
        $this->maxFileSize = 50 * 1024 * 1024;
        $this->compiledSanitizePatterns = null;
    }

    /** @param array{headers?: array<int, string>, body?: array<int, string>, query?: array<int, string>} $config */
    public function setSanitizeConfig(array $config): void
    {
        if (isset($config['headers'])) $this->sanitizeConfig['headers'] = $config['headers'];
        if (isset($config['body'])) $this->sanitizeConfig['body'] = $config['body'];
        if (isset($config['query'])) $this->sanitizeConfig['query'] = $config['query'];
    }

    public function request(string $method, string $path, int $status, float $timeMs, string $ip = '', string $traceId = '', string $userAgent = ''): void
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
            $parts[] = 'ua:' . $this->escapeLog(mb_substr($userAgent, 0, 60));
        }

        $this->write('request', implode(' | ', $parts));
    }

    public function slowRequest(string $method, string $path, int $status, float $timeMs): void
    {
        if ($timeMs <= $this->slowThreshold) {
            return;
        }

        $line = sprintf(
            '[%s] %s %s %d %.2fms (threshold: %dms)',
            date('Y-m-d H:i:s'), strtoupper($method), $path, $status, $timeMs, $this->slowThreshold
        );

        $this->write('slow', $line, true);
    }

    public function error(Throwable|string $error): void
    {
        if ($error instanceof Throwable) {
            $message = $this->sanitize($error->getMessage());
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
                $line .= PHP_EOL . '  Stack trace:' . PHP_EOL . '  ' . str_replace(PHP_EOL, PHP_EOL . '  ', $this->sanitize($trace));
            }
        } else {
            $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), $this->sanitize($error));
        }

        $this->write('error', $this->escapeLog($line), true);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $line = sprintf('[%s] [DEBUG] %s', date('Y-m-d H:i:s'), $this->sanitize($message));
        if ($context !== []) {
            $line .= ' ' . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->write('debug', $this->escapeLog($line), false);
    }

    /**
     * Security audit log — always written, separate file for SIEM.
     *
     * @param string $event Event type (auth.failed, csrf.failed, etc.)
     * @param array<string, mixed> $context
     */
    public function security(string $event, array $context = []): void
    {
        $line = sprintf(
            '[%s] [SECURITY] %s %s',
            date('Y-m-d H:i:s'),
            $event,
            (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->write('security', $this->escapeLog($line), true);
    }

    /** @param array<string, mixed> $data */
    public function trace(string $traceId, array $data): void
    {
        $data['timestamp'] = date('Y-m-d H:i:s');
        $data['trace_id'] = $traceId;

        if (isset($data['request_headers']) && is_array($data['request_headers'])) {
            /** @var array<string, string> $reqHeaders */
            $reqHeaders = $data['request_headers'];
            $data['request_headers'] = $this->sanitizeHeaders($reqHeaders);
        }

        if (isset($data['request_body']) && is_string($data['request_body'])) {
            $data['request_body'] = $this->sanitizeJsonBody($data['request_body']);
        }

        if (isset($data['response_body']) && is_string($data['response_body'])) {
            $data['response_body'] = $this->sanitizeJsonBody($data['response_body']);
        }

        if (isset($data['query_params']) && is_array($data['query_params'])) {
            foreach ($this->sanitizeConfig['query'] as $field) {
                if (isset($data['query_params'][$field])) {
                    $data['query_params'][$field] = '[REDACTED]';
                }
            }
        }

        $this->writeTrace($traceId, $data);
    }

    /** @var array{patterns: array<int, string>, replacements: array<int, string>}|null */
    private ?array $compiledSanitizePatterns = null;

    /** @return array{patterns: array<int, string>, replacements: array<int, string>} */
    private function getSanitizePatterns(): array
    {
        if ($this->compiledSanitizePatterns !== null) {
            return $this->compiledSanitizePatterns;
        }

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
            '/"authorization"\s*:\s*"[^"]+"/i' => '"authorization":"[REDACTED]"',
            '/"(bearer|token)"\s*:\s*"[^"]{8,}"/i' => '"$1":"[REDACTED]"',
            '/"(password|passwd|pass)"\s*:\s*"[^"]{3,}"/i' => '"$1":"[REDACTED]"',
            '/"(secret|api_key|apikey)"\s*:\s*"[^"]{4,}"/i' => '"$1":"[REDACTED]"',
            '/"(otp|otp_code|otp_secret)"\s*:\s*"[^"]{4,}"/i' => '"$1":"[REDACTED]"',
            '/"(credit_card|card_number|cvv|cvc|ssn|passport)"\s*:\s*"[^"]{4,}"/i' => '"$1":"[REDACTED]"',
            '/"(session_id|sessionid)"\s*:\s*"[^"]{4,}"/i' => '"$1":"[REDACTED]"',
            '/"(refresh_token|access_token)"\s*:\s*"[^"]{8,}"/i' => '"$1":"[REDACTED]"',
            '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})\b/' => '[REDACTED-CARD]',
        ];

        $this->compiledSanitizePatterns = [
            'patterns' => array_keys($sensitive),
            'replacements' => array_values($sensitive),
        ];

        return $this->compiledSanitizePatterns;
    }

    public function sanitize(string $message): string
    {
        $message = $this->escapeLog($message);

        if (!preg_match('/\b(password|secret|token|key|auth)\b/i', $message)) {
            return $message;
        }

        $patterns = $this->getSanitizePatterns();

        /** @var array<int, string> $patternList */
        $patternList = $patterns['patterns'];
        /** @var array<int, string> $replacementList */
        $replacementList = $patterns['replacements'];
        return (string) preg_replace($patternList, $replacementList, $message);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sanitizeHeaders(array $headers): array
    {
        foreach ($headers as $key => $value) {
            $lk = strtolower((string) $key);
            if (in_array($lk, $this->sanitizeConfig['headers'], true)) {
                $headers[$key] = '[REDACTED]';
            }
        }
        return $headers;
    }

    public function sanitizeJsonBody(string $json): string
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return $json;

        $decoded = $this->sanitizeRecursive($decoded, $this->sanitizeConfig['body']);

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : $json;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param array<int, string> $sensitiveFields
     * @return array<mixed, mixed>
     */
    private function sanitizeRecursive(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => &$value) {
            $lk = strtolower((string) $key);
            if (is_array($value)) {
                $value = $this->sanitizeRecursive($value, $sensitiveFields);
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

    public function escapeLog(string $message): string
    {
        return str_replace(["\r\n", "\n", "\r"], '\n', $message);
    }

    public function getLogDir(): string
    {
        return $this->logDir;
    }

    private function write(string $type, string $line, bool $alsoDaily = false): void
    {
        if ($this->logDir === '') {
            $this->logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }

        $dailyFile = $this->logDir . DIRECTORY_SEPARATOR . $type . '-' . date('Y-m-d') . '.log';
        $line = $this->escapeLog($line) . PHP_EOL;

        error_log($line, 3, $dailyFile);

        if ($alsoDaily) {
            $mainFile = $this->logDir . DIRECTORY_SEPARATOR . $type . '.log';
            error_log($line, 3, $mainFile);
            if (is_file($mainFile) && filesize($mainFile) > $this->maxFileSize) {
                $rotated = $mainFile . '.' . date('Y-m-d-Hi');
                rename($mainFile, $rotated);
            }
        }

        if ($this->shouldCleanLogs()) {
            $this->cleanOldLogs();
        }
    }

    private bool $logsCleaned = false;

    private function shouldCleanLogs(): bool
    {
        if ($this->logsCleaned) {
            return false;
        }
        $this->logsCleaned = true;
        return true;
    }

    /** @param array<string, mixed> $data */
    private function writeTrace(string $traceId, array $data): void
    {
        $traceDir = $this->logDir . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) {
            mkdir($traceDir, 0775, true);
        }

        $traceFile = $traceDir . DIRECTORY_SEPARATOR . $traceId . '.json';
        file_put_contents($traceFile, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        if ($this->shouldCleanTraces()) {
            $this->cleanOldTraces();
        }
    }

    private bool $tracesCleaned = false;

    private function shouldCleanTraces(): bool
    {
        if ($this->tracesCleaned) {
            return false;
        }
        $this->tracesCleaned = true;
        return true;
    }

    private function cleanOldTraces(): void
    {
        $traceDir = $this->logDir . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) return;

        $cutoff = time() - ($this->retentionDays * 86400);
        foreach (glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff && is_file($file)) unlink($file);
        }
    }

    private function cleanOldLogs(): void
    {
        if ($this->logDir === '' || !is_dir($this->logDir)) return;

        $cutoff = time() - ($this->retentionDays * 86400);

        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . '*-????-??-??.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && is_file($file)) unlink($file);
        }
        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . '*.log.*') ?: [] as $file) {
            if (filemtime($file) < $cutoff && is_file($file)) unlink($file);
        }
    }

    private function protectLogDir(): void
    {
        $logDir = $this->logDir;
        $traceDir = $logDir . DIRECTORY_SEPARATOR . 'traces';

        foreach ([$logDir, $traceDir] as $dir) {
            if (!is_dir($dir)) continue;
            $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }

        $nginxConf = $logDir . DIRECTORY_SEPARATOR . 'nginx-deny.conf';
        if (!file_exists($nginxConf)) {
            file_put_contents($nginxConf, "deny all;\n");
        }

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
