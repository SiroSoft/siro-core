<?php

declare(strict_types=1);

namespace Siro\Core;

final class Request
{
    private readonly string $method;
    private readonly string $path;
    /** @var array<string, mixed> */
    private readonly array $queryParams;
    /** @var array<string, string> */
    private readonly array $headerBag;
    /** @var array<string, mixed> */
    private readonly array $bodyData;
    /** @var array<string, string> */
    private array $routeParams = [];
    /** @var array<string, mixed>|null */
    private ?array $authenticatedUser = null;
    private readonly string $clientIp;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<string, mixed> $jsonBody
     */
    public function __construct(string $method, string $path, array $query, array $headers, array $jsonBody, string $clientIp)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->queryParams = $query;
        $this->headerBag = $headers;
        $this->bodyData = $jsonBody;
        $this->clientIp = $clientIp;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = self::normalizePath($path);

        $query = $_GET;
        $headers = self::parseHeaders();

        $rawBody = file_get_contents('php://input') ?: '';
        $jsonBody = [];

        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Malformed JSON - will be caught by validation later
                // Store empty array to prevent fatal errors
                $jsonBody = [];
            } elseif (is_array($decoded)) {
                $jsonBody = $decoded;
            }
        }

        $clientIp = self::resolveClientIp();

        return new self($method, $path, $query, $headers, $jsonBody, $clientIp);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->bodyData;
    }

    /** @return array<string, mixed> */
    public function jsonAll(): array
    {
        return $this->body();
    }

    /** @return array<string, mixed> */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }

        return $this->queryParams[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query();
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headerBag;
    }

    /** @return array<string, string> */
    public function headersAll(): array
    {
        return $this->headers();
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        return $this->headerBag[$key] ?? $default;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /** @param array<string, string> $params */
    public function setParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->bodyData)) {
            return $this->bodyData[$key];
        }

        if (array_key_exists($key, $this->routeParams)) {
            return $this->routeParams[$key];
        }

        if (array_key_exists($key, $this->queryParams)) {
            return $this->queryParams[$key];
        }

        return $default;
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->authenticatedUser = $user;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->authenticatedUser;
    }

    public function ip(): string
    {
        return $this->clientIp;
    }

    public function cacheKey(): string
    {
        $query = $this->queryParams;
        if ($query !== []) {
            ksort($query);
        }

        $queryString = http_build_query($query);
        $suffix = $queryString !== '' ? ('?' . $queryString) : '';

        return $this->method . ':' . $this->path . $suffix;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $normalized = '/' . trim($path, '/');
        return $normalized === '//' || $normalized === '' ? '/' : ($normalized === '/.' ? '/' : $normalized);
    }

    private static function resolveClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $remoteIp = (is_string($remoteAddr) && self::isValidIp($remoteAddr)) ? $remoteAddr : '0.0.0.0';

        $trustedProxies = self::trustedProxies();
        $isFromTrustedProxy = $remoteIp !== '0.0.0.0' && in_array($remoteIp, $trustedProxies, true);
        if (!$isFromTrustedProxy) {
            return $remoteIp;
        }

        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwardedFor) && $forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $candidate) {
                $ip = trim($candidate);
                if (self::isValidIp($ip)) {
                    return $ip;
                }
            }
        }

        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if (is_string($realIp) && self::isValidIp(trim($realIp))) {
            return trim($realIp);
        }

        return $remoteIp;
    }

    /** @return array<int, string> */
    private static function trustedProxies(): array
    {
        $raw = Env::get('APP_TRUSTED_PROXIES', '') ?? '';
        $items = array_map('trim', explode(',', $raw));

        $trusted = [];
        foreach ($items as $item) {
            if ($item !== '' && self::isValidIp($item)) {
                $trusted[] = $item;
            }
        }

        return $trusted;
    }

    private static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** @return array<string, string> */
    private static function parseHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }

            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $headers['authorization'] = 'Basic ' . base64_encode(
                (string) $_SERVER['PHP_AUTH_USER'] . ':' . (string) ($_SERVER['PHP_AUTH_PW'] ?? '')
            );
        }

        return $headers;
    }
}
