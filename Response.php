<?php

declare(strict_types=1);

namespace Siro\Core;

final class Response
{
    private static bool $debugEnabled = false;
    /** @var array<string, float|int|string|bool|null> */
    private static array $debugMeta = [];

    /** @var array<string, mixed> */
    private array $payload;
    private readonly int $statusCode;

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
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $statusCode = 200): self
    {
        return new self($payload, $statusCode);
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

    public function send(): void
    {
        if (self::$debugEnabled) {
            $this->payload['debug'] = self::$debugMeta;
        }

        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        $encoded = json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            http_response_code(500);
            echo '{"success":false,"message":"JSON encoding error","errors":{}}';
            return;
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
