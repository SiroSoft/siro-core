<?php

declare(strict_types=1);

namespace Siro\Core\Debug;

/**
 * Static trace data collector for the request lifecycle.
 *
 * Middleware, Router, and App enrich this during a request,
 * then App writes it all to the trace file at the end.
 *
 * @package Siro\Core\Debug
 */
final class TraceData
{
    /** @var array<int, array{name:string, passed:bool, time_ms:float}> */
    private static array $middleware = [];

    /** @var array<int, array{sql:string, time_ms:float, rows:int}> */
    private static array $queries = [];

    private static string $requestBody = '';
    private static string $responseBody = '';

    /** @var array<string, string> */
    private static array $requestHeaders = [];

    private static string $authHeader = '';
    private static string $contentType = '';

    /** @var array{class:string, message:string}|null */
    private static ?array $exception = null;

    public static function reset(): void
    {
        self::$middleware = [];
        self::$queries = [];
        self::$requestBody = '';
        self::$responseBody = '';
        self::$requestHeaders = [];
        self::$authHeader = '';
        self::$contentType = '';
        self::$exception = null;
    }

    public static function addMiddleware(string $name, bool $passed, float $timeMs): void
    {
        self::$middleware[] = ['name' => $name, 'passed' => $passed, 'time_ms' => $timeMs];
    }

    public static function addQuery(string $sql, float $timeMs, int $rows): void
    {
        self::$queries[] = ['sql' => $sql, 'time_ms' => $timeMs, 'rows' => $rows];
    }

    public static function setRequestBody(string $body): void
    {
        self::$requestBody = $body;
    }

    public static function setResponseBody(string $body): void
    {
        self::$responseBody = $body;
    }

    /** @param array<string, string> $headers */
    public static function setRequestHeaders(array $headers): void
    {
        $sanitized = [];
        foreach ($headers as $key => $value) {
            $lk = strtolower($key);
            if ($lk === 'authorization') {
                self::$authHeader = substr($value, 0, 15) . '...[REDACTED]';
                $sanitized[$key] = '[REDACTED]';
            } elseif ($lk === 'content-type') {
                self::$contentType = $value;
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = $value;
            }
        }
        self::$requestHeaders = $sanitized;
    }

    public static function setException(string $class, string $message): void
    {
        self::$exception = ['class' => $class, 'message' => $message];
    }

    /** @return array<string, mixed> */
    public static function getAll(): array
    {
        $data = [];
        if (self::$middleware !== []) {
            $data['middleware'] = self::$middleware;
        }
        if (self::$queries !== []) {
            $data['queries'] = self::$queries;
        }
        if (self::$requestBody !== '') {
            $data['request_body'] = self::$requestBody;
        }
        if (self::$responseBody !== '') {
            $data['response_body'] = self::$responseBody;
        }
        if (self::$requestHeaders !== []) {
            $data['request_headers'] = self::$requestHeaders;
        }
        if (self::$authHeader !== '') {
            $data['auth_header'] = self::$authHeader;
        }
        if (self::$contentType !== '') {
            $data['content_type'] = self::$contentType;
        }
        if (self::$exception !== null) {
            $data['exception'] = self::$exception;
        }
        return $data;
    }
}
