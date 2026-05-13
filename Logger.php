<?php

declare(strict_types=1);

namespace Siro\Core;

use Throwable;
use Siro\Core\Logger\LoggerInterface;
use Siro\Core\Logger\LoggerInstance;

final class Logger
{
    private static ?LoggerInterface $instance = null;

    public static function getInstance(): LoggerInterface
    {
        if (self::$instance === null) {
            $container = Container::getInstance();
            if ($container->has(LoggerInterface::class)) {
                self::$instance = $container->make(LoggerInterface::class);
            } else {
                self::$instance = new LoggerInstance();
            }
        }
        return self::$instance;
    }

    public static function setInstance(?LoggerInterface $instance): void
    {
        self::$instance = $instance;
    }

    public static function boot(string $basePath): void { self::getInstance()->boot($basePath); }
    public static function reset(): void { self::getInstance()->reset(); }
    public static function setSanitizeConfig(array $config): void { self::getInstance()->setSanitizeConfig($config); }
    public static function request(string $method, string $path, int $status, float $timeMs, string $ip = '', string $traceId = '', string $userAgent = ''): void { self::getInstance()->request($method, $path, $status, $timeMs, $ip, $traceId, $userAgent); }
    public static function slowRequest(string $method, string $path, int $status, float $timeMs): void { self::getInstance()->slowRequest($method, $path, $status, $timeMs); }
    public static function error(Throwable|string $error): void { self::getInstance()->error($error); }
    public static function debug(string $message, array $context = []): void { self::getInstance()->debug($message, $context); }
    public static function security(string $event, array $context = []): void { self::getInstance()->security($event, $context); }
    public static function trace(string $traceId, array $data): void { self::getInstance()->trace($traceId, $data); }
    public static function sanitize(string $message): string { return self::getInstance()->sanitize($message); }
    public static function sanitizeHeaders(array $headers): array { return self::getInstance()->sanitizeHeaders($headers); }
    public static function sanitizeJsonBody(string $json): string { return self::getInstance()->sanitizeJsonBody($json); }
    public static function escapeLog(string $message): string { return self::getInstance()->escapeLog($message); }
    public static function getLogDir(): string { return self::getInstance()->getLogDir(); }
}
