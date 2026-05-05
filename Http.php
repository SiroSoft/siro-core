<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

final class Http
{
    private static int $timeout = 30;
    private static array $defaultHeaders = [];

    public static function timeout(int $seconds): void
    {
        self::$timeout = max(1, $seconds);
    }

    public static function withHeaders(array $headers): void
    {
        self::$defaultHeaders = array_merge(self::$defaultHeaders, $headers);
    }

    public static function get(string $url, array $query = [], array $headers = []): HttpResponse
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return self::request('GET', $url, null, $headers);
    }

    public static function post(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('POST', $url, $data, $headers);
    }

    public static function put(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('PUT', $url, $data, $headers);
    }

    public static function patch(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('PATCH', $url, $data, $headers);
    }

    public static function delete(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return self::request('DELETE', $url, $data, $headers);
    }

    private static function request(string $method, string $url, mixed $data, array $headers): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension is required for HTTP client.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::$timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADER => true,
        ]);

        $allHeaders = array_merge(self::$defaultHeaders, $headers);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($data !== null) {
            if (is_array($data) && !isset($allHeaders['Content-Type'])) {
                $data = http_build_query($data);
            } elseif (is_array($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
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
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $headerSize = (int) ($info['header_size'] ?? 0);
        $body = substr((string) $response, $headerSize);
        $rawHeaders = substr((string) $response, 0, $headerSize);

        return new HttpResponse(
            (int) ($info['http_code'] ?? 0),
            $body,
            $rawHeaders,
        );
    }
}

final class HttpResponse
{
    private int $status;
    private string $body;
    private string $rawHeaders;
    private ?array $parsedJson = null;

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

    public function json(): array
    {
        if ($this->parsedJson === null) {
            $this->parsedJson = json_decode($this->body, true) ?? [];
        }
        return $this->parsedJson;
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

    public function headers(): array
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
