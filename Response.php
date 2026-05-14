<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

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
    /** @var array<string, mixed> */
    private static array $debugMeta = [];
    private static string $requestId = '';
    private static float $requestStartedAt = 0.0;

    /** @var array<string, mixed> */
    private array $payload;
    private readonly int $statusCode;
    /** @var array<string, string> */
    private array $extraHeaders = [];
    private bool $isFileResponse = false;
    private string $filePath = '';
    private bool $isRaw = false;
    private string $rawContent = '';

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
    /** @param array<string, mixed> $meta */
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
        $payload = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => [
                'errors' => $errors,
            ],
        ];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        return new self($payload, $statusCode);
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
        $response = new self([], $statusCode);
        $response->isRaw = true;
        $response->rawContent = $content;
        $response->extraHeaders['Content-Type'] = $contentType;
        return $response;
    }

    /**
     * Create a file download response.
     */
    private static function sanitizeDownloadPath(string $filePath): string
    {
        // Resolve symlinks and relative paths, validate within project
        $real = realpath($filePath);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('File not found: ' . $filePath);
        }
        // Ensure file is within project directory to prevent path traversal
        $base = defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : (string) getcwd();
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        if (!str_starts_with($real, $base)) {
            throw new RuntimeException('Access denied: file is outside project directory');
        }
        return $real;
    }

    private static function sanitizeFilename(string $filename): string
    {
        // Strip all dangerous characters for HTTP headers
        $clean = preg_replace('/[^\p{L}\p{N}\s._\-,()\[\]!@#$%^&+=~]/u', '', $filename) ?? $filename;
        $clean = preg_replace('/\R/', '', $clean) ?? $clean;
        $clean = str_replace(['"', '\\', "\0", "\x00"], '', $clean);
        return trim($clean);
    }

    /** @param array<string, string> $headers */ public static function download(string $filePath, ?string $filename = null, array $headers = []): self
    {
        try {
            $filePath = self::sanitizeDownloadPath($filePath);
        } catch (RuntimeException $e) {
            return self::error($e->getMessage(), 404);
        }

        $response = new self([], 200);
        $response->isFileResponse = true;
        $response->filePath = $filePath;
        if ($filename !== null) {
            $filename = self::sanitizeFilename($filename);
        }
        $disposition = $filename ? 'attachment; filename="' . $filename . '"' : 'attachment';
        $response->extraHeaders['Content-Disposition'] = $disposition;
        $response->extraHeaders['Content-Type'] = mime_content_type($filePath) ?: 'application/octet-stream';
        $response->extraHeaders['Content-Length'] = (string) filesize($filePath);

        foreach ($headers as $name => $value) {
            $response->extraHeaders[$name] = $value;
        }

        return $response;
    }

    /**
     * Create a file download response from storage path.
     */
    public static function downloadFromStorage(string $path, ?string $filename = null): self
    {
        $fullPath = Storage::localPath($path);

        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return self::error('File not found', 404);
        }

        return self::download($fullPath, $filename);
    }

    /**
     * Create a stream response for large file downloads.
     */
    public static function stream(string $filePath, ?string $filename = null, int $chunkSize = 8192): self
    {
        try {
            $filePath = self::sanitizeDownloadPath($filePath);
        } catch (RuntimeException $e) {
            return self::error($e->getMessage(), 404);
        }

        $response = new self([], 200);
        $response->isFileResponse = true;
        $response->filePath = $filePath;
        $downloadFilename = self::sanitizeFilename($filename ?? basename($filePath));
        $response->extraHeaders['Content-Disposition'] = 'attachment; filename="' . $downloadFilename . '"';
        $response->extraHeaders['Content-Type'] = mime_content_type($filePath) ?: 'application/octet-stream';
        $response->extraHeaders['Accept-Ranges'] = 'bytes';
        $response->extraHeaders['Content-Length'] = (string) filesize($filePath);
        $response->extraHeaders['X-Chunk-Size'] = (string) $chunkSize;

        return $response;
    }

    /**
     * Create a file response (inline display).
     */
    /** @param array<string, string> $headers */ public static function file(string $filePath, ?string $contentType = null, array $headers = []): self
    {
        try {
            $filePath = self::sanitizeDownloadPath($filePath);
        } catch (RuntimeException $e) {
            return self::error($e->getMessage(), 404);
        }

        $response = new self([], 200);
        $response->isFileResponse = true;
        $response->filePath = $filePath;
        $response->extraHeaders['Content-Disposition'] = 'inline';
        $response->extraHeaders['Content-Type'] = $contentType ?? (mime_content_type($filePath) ?: 'application/octet-stream');
        $response->extraHeaders['Content-Length'] = (string) filesize($filePath);

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

    /** @param array<string, mixed> $meta */
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

    public function isFileResponse(): bool
    {
        return $this->isFileResponse;
    }

    public function getHeader(string $name): ?string
    {
        return $this->extraHeaders[$name] ?? null;
    }

    /** @return array<int, string> */
    public function getHeaders(): array
    {
        $headers = [];
        foreach ($this->extraHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        return $headers;
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
        $isCli = php_sapi_name() === 'cli';

        if ($this->isFileResponse) {
            $this->sendFile($isCli);
            return;
        }

        if ($this->isRaw) {
            $this->sendRaw($isCli);
            return;
        }

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

        if (!$isCli) {
            $acceptEncoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && is_scalar($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
            if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
                header('Content-Encoding: gzip');
                echo gzencode($encoded);
                return;
            }
        }

        echo $encoded;
    }

    private function sendFile(bool $isCli): void
    {
        if (!$isCli) {
            http_response_code($this->statusCode);
            foreach ($this->extraHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
            readfile($this->filePath);
        } else {
            echo '[File: ' . $this->filePath . ']';
        }
    }

    private function sendRaw(bool $isCli): void
    {
        if (!$isCli) {
            http_response_code($this->statusCode);
            foreach ($this->extraHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->rawContent;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->extraHeaders;
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
