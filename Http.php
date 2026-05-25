<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Zero-dependency HTTP client.
 *
 * Static methods for GET/POST/PUT/PATCH/DELETE requests via cURL.
 * Returns HttpResponse objects with status, body, JSON parsing, and headers.
 *
 * @package Siro\Core
 */

final class Http
{
    private static int $timeout = 30;
    private static bool $verifySsl = true;
    private static bool $sslConfigured = false;
    /** @var array<string, string> */ private static array $defaultHeaders = [];

    public static function timeout(int $seconds): void
    {
        self::$timeout = max(1, $seconds);
    }

    public static function verify(bool $verify = true): void
    {
        if (self::$sslConfigured) {
            return;
        }
        self::$verifySsl = $verify;
        self::$sslConfigured = true;
    }

    /** @param array<string, string> $headers */ public static function withHeaders(array $headers): void
    {
        self::$defaultHeaders = array_merge(self::$defaultHeaders, $headers);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public static function get(string $url, array $query = [], array $headers = []): HttpResponse
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return self::request('GET', $url, null, $headers);
    }

    /** @param array<string, string> $headers */ public static function post(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('POST', $url, $data, $headers);
    }

    /** @param array<string, string> $headers */ public static function put(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('PUT', $url, $data, $headers);
    }

    /** @param array<string, string> $headers */ public static function patch(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('PATCH', $url, $data, $headers);
    }

    /** @param array<string, string> $headers */ public static function delete(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('DELETE', $url, $data, $headers);
    }

    /** @param array<string, string> $headers */ private static function request(string $method, string $url, mixed $data, array $headers): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension is required for HTTP client.');
        }

        $ch = curl_init();
        /** @var array<int, mixed> $curlOpts */
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::$timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HEADER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if (!self::$verifySsl) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $curlOpts);

        $allHeaders = array_merge(self::$defaultHeaders, $headers);

        if ($method !== 'GET' && $method !== '') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($data !== null) {
            if (is_array($data) && !isset($allHeaders['Content-Type'])) {
                $data = http_build_query($data);
            } elseif (is_array($data)) {
                $data = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            if (is_string($data) && $data !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $data);
            }
        }

        if ($allHeaders !== []) {
            $formatted = [];
            foreach ($allHeaders as $k => $v) {
                $formatted[] = "{$k}: {$v}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        /** @var array{header_size: int, http_code: int} $info */
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $headerSize = $info['header_size'];
        $body = substr((string) $response, $headerSize);
        $rawHeaders = substr((string) $response, 0, $headerSize);

        return new HttpResponse(
            $info['http_code'],
            $body,
            $rawHeaders,
        );
    }
}

/**
 * HTTP response wrapper returned by Http:: methods.
 *
 * @package Siro\Core
 */
final class HttpResponse
{
    private int $status;
    private string $body;
    private string $rawHeaders;
    /** @var array<string, mixed>|null */ private ?array $parsedJson = null;

    public function __construct(int $status, string $body, string $rawHeaders)
    {
        $this->status = $status;
        $this->body = $body;
        $this->rawHeaders = $rawHeaders;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed> */ public function json(): array
    {
        if ($this->parsedJson === null) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->body, true) ?? [];
            $this->parsedJson = $decoded;
        }
        return $this->parsedJson ?? [];
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    public function header(string $name): string
    {
        $regex = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';
        preg_match($regex, $this->rawHeaders, $matches);
        return trim($matches[1] ?? '');
    }

    /** @return array<string, string> */ public function headers(): array
    {
        $lines = explode("\r\n", trim($this->rawHeaders));
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ': ')) {
                [$k, $v] = explode(': ', $line, 2);
                $headers[strtolower($k)] = $v;
            }
        }
        return $headers;
    }
}
