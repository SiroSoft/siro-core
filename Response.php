<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * JSON response builder and HTTP sender.
 *
 * Provides static factories for common API responses (success, error,
 * created, noContent, paginated) and fluent header customization.
 * Automatically sets security headers and handles JSON encoding.
 *
 * @package Siro\Core
 */
final class Response
{
    private static bool $debugEnabled = false;
    /** @var array<string, float|int|string|bool|null> */
    private static array $debugMeta = [];
    private static string $requestId = '';
    private static float $requestStartedAt = 0.0;

    /** @var array<string, mixed> */
    private array $payload;
    private readonly int $statusCode;
    /** @var array<string, string> */
    private array $extraHeaders = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload, int $statusCode = 200)
    {
        $this->payload = $payload;
        $this->statusCode = $statusCode;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data = null, string $message = 'OK', int $statusCode = 200, array $meta = []): self
    {
        return new self([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function error(string $message, int $statusCode = 400, array $errors = []): self
    {
        return new self([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => [
                'errors' => $errors,
            ],
        ], $statusCode);
    }

    public static function created(mixed $data = null, string $message = 'Created'): self
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): self
    {
        return new self([
            'success' => true,
            'message' => 'No Content',
            'data' => null,
            'meta' => [],
        ], 204);
    }

    /**
     * Create a redirect response.
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self([], $statusCode);
        $response->extraHeaders['Location'] = $url;
        return $response;
    }

    /**
     * Create a raw response with custom content type.
     */
    public static function raw(string $content, string $contentType = 'text/plain', int $statusCode = 200): self
    {
        $response = new self(['raw' => $content], $statusCode);
        $response->extraHeaders['Content-Type'] = $contentType;
        return $response;
    }

    /**
     * Create a file download response.
     */
    public static function download(string $filePath, ?string $filename = null, array $headers = []): self
    {
        $response = new self(['file' => $filePath], 200);
        $disposition = $filename ? 'attachment; filename="' . $filename . '"' : 'attachment';
        $response->extraHeaders['Content-Disposition'] = $disposition;
        $response->extraHeaders['Content-Type'] = 'application/octet-stream';
        
        foreach ($headers as $name => $value) {
            $response->extraHeaders[$name] = $value;
        }
        
        return $response;
    }

    /**
     * Create a file response (inline display).
     */
    public static function file(string $filePath, ?string $contentType = null, array $headers = []): self
    {
        $response = new self(['file' => $filePath], 200);
        $response->extraHeaders['Content-Disposition'] = 'inline';
        $response->extraHeaders['Content-Type'] = $contentType ?? 'application/octet-stream';
        
        foreach ($headers as $name => $value) {
            $response->extraHeaders[$name] = $value;
        }
        
        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $statusCode = 200): self
    {
        return new self($payload, $statusCode);
    }

    /**
     * Create a paginated response.
     *
     * @param array<int, mixed> $data
     * @param array{page: int, per_page: int, total: int, last_page: int} $meta
     */
    public static function paginated(array $data, array $meta, string $message = 'OK', int $statusCode = 200): self
    {
        return new self([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $statusCode);
    }

    public static function enableDebug(bool $enabled): void
    {
        self::$debugEnabled = $enabled;
    }

    /** @param array<string, float|int|string|bool|null> $meta */
    public static function setDebugMeta(array $meta): void
    {
        self::$debugMeta = $meta;
    }

    public static function setRequestMeta(string $requestId, float $startedAt): void
    {
        self::$requestId = $requestId;
        self::$requestStartedAt = $startedAt;
    }

    public function header(string $name, string $value): self
    {
        $this->extraHeaders[$name] = $value;
        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->extraHeaders[$name] = $value;
        }
        return $this;
    }

    public function send(): void
    {
        // Only send headers in web context, not CLI
        $isCli = php_sapi_name() === 'cli';
        
        if (self::$debugEnabled) {
            $this->payload['debug'] = self::$debugMeta;
        }

        if (!$isCli) {
            http_response_code($this->statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');

            if (self::$requestId !== '') {
                header('X-Request-Id: ' . self::$requestId);
            }
            if (self::$requestStartedAt > 0.0) {
                $elapsed = (microtime(true) - self::$requestStartedAt) * 1000;
                header('X-Response-Time: ' . number_format($elapsed, 2) . 'ms');
            }

            foreach ($this->extraHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        $encoded = json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            if (!$isCli) {
                http_response_code(500);
            }
            echo '{"success":false,"message":"JSON encoding error","errors":{}}';
            return;
        }

        // Gzip compression if accepted by client (only in web context)
        if (!$isCli) {
            $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
                header('Content-Encoding: gzip');
                echo gzencode($encoded);
                return;
            }
        }

        echo $encoded;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
