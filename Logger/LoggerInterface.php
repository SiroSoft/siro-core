<?php

declare(strict_types=1);

namespace Siro\Core\Logger;

use Throwable;

interface LoggerInterface
{
    public function boot(string $basePath): void;
    public function reset(): void;
    public function setSanitizeConfig(array $config): void;
    public function request(string $method, string $path, int $status, float $timeMs, string $ip = '', string $traceId = '', string $userAgent = ''): void;
    public function slowRequest(string $method, string $path, int $status, float $timeMs): void;
    public function error(Throwable|string $error): void;
    public function debug(string $message, array $context = []): void;
    public function security(string $event, array $context = []): void;
    public function trace(string $traceId, array $data): void;
    public function sanitize(string $message): string;
    public function sanitizeHeaders(array $headers): array;
    public function sanitizeJsonBody(string $json): string;
    public function escapeLog(string $message): string;
    public function getLogDir(): string;
}
