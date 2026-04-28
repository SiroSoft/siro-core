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
    /** @var array<string, UploadedFile> */
    private array $uploadedFiles = [];
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
        $contentType = $headers['content-type'] ?? '';
        $isMultipart = str_contains($contentType, 'multipart/form-data');

        $maxBodySize = 2 * 1024 * 1024; // 2MB limit
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBodySize) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Request body too large'], JSON_UNESCAPED_UNICODE);
            exit(1);
        }

        $jsonBody = [];

        if ($isMultipart) {
            // For multipart, PHP automatically populates $_POST and $_FILES
            $jsonBody = $_POST;
        } else {
            $rawBody = file_get_contents('php://input') ?: '';

            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $jsonBody = [];
                } elseif (is_array($decoded)) {
                    $jsonBody = $decoded;
                }
            }
        }

        $clientIp = self::resolveClientIp();
        $request = new self($method, $path, $query, $headers, $jsonBody, $clientIp);

        if ($isMultipart) {
            $request->parseUploadedFiles();
        }

        return $request;
    }

    private function parseUploadedFiles(): void
    {
        foreach ($_FILES as $key => $file) {
            if (!is_array($file) || !isset($file['tmp_name'])) {
                continue;
            }

            if (is_array($file['tmp_name'])) {
                $this->parseNestedFiles($key, $file);
            } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                $this->uploadedFiles[$key] = new UploadedFile($file);
            }
        }
    }

    private function parseNestedFiles(string $key, array $file): void
    {
        $names = is_array($file['name']) ? $file['name'] : [];
        $tmpNames = is_array($file['tmp_name']) ? $file['tmp_name'] : [];
        $types = is_array($file['type']) ? $file['type'] : [];
        $sizes = is_array($file['size']) ? $file['size'] : [];
        $errors = is_array($file['error']) ? $file['error'] : [];

        foreach ($tmpNames as $index => $tmpName) {
            if (isset($errors[$index]) && (int) $errors[$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $this->uploadedFiles[$key . '.' . $index] = new UploadedFile([
                'name' => $names[$index] ?? '',
                'tmp_name' => $tmpName,
                'type' => $types[$index] ?? '',
                'size' => $sizes[$index] ?? 0,
                'error' => $errors[$index] ?? UPLOAD_ERR_NO_FILE,
            ]);
        }
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

    public function hasFile(string $key): bool
    {
        return isset($this->uploadedFiles[$key]) && $this->uploadedFiles[$key]->isValid();
    }

    public function file(string $key): ?UploadedFile
    {
        return $this->uploadedFiles[$key] ?? null;
    }

    /** @return array<string, UploadedFile> */
    public function allFiles(): array
    {
        return $this->uploadedFiles;
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

    /**
     * Validate request data using Validator.
     * Automatically throws ValidationException on failure (returns 422).
     * Returns only the fields that were validated.
     *
     * @param array<string, string> $rules
     * @return array<string, mixed> Validated data (only fields with rules)
     * @throws ValidationException
     */
    public function validate(array $rules): array
    {
        $data = $this->body();
        $errors = Validator::make($data, $rules);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * Alias for validate(). Kept for compatibility.
     *
     * @param array<string, string> $rules
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validated(array $rules): array
    {
        return $this->validate($rules);
    }

    /**
     * Return only the specified keys from the request body.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $data = $this->body();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    /**
     * Return all request data except the specified keys.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        $data = $this->body();

        foreach ($keys as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Get input as integer.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return (int) $value;
    }

    /**
     * Get input as string.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return (string) $value;
    }

    /**
     * Get input as boolean.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Get input as array.
     *
     * @return array<int|string, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * Get input as float.
     */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);
        return (float) $value;
    }

    /**
     * Get query parameter as integer.
     */
    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query($key, $default);
        return (int) $value;
    }

    /**
     * Get query parameter as string.
     */
    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query($key, $default);
        return (string) $value;
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
