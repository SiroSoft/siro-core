<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

final class Session
{
    public const DRIVER_FILE = 'file';
    public const DRIVER_REDIS = 'redis';

    private static ?Session $instance = null;
    private static ?\Redis $redisInstance = null;
    private string $driver;
    private string $filePath;
    private string $sessionId;
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var array<mixed> */
    private array $flash = [];
    private bool $started = false;

    public function __construct(?string $driver = null)
    {
        $this->driver = $driver ?? (is_string($sessionDriver = Env::get('SESSION_DRIVER', self::DRIVER_FILE)) ? $sessionDriver : self::DRIVER_FILE);
        $basePath = '';
        if (defined('BASE_PATH') && is_string(BASE_PATH)) {
            $basePath = BASE_PATH;
        } elseif (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH)) {
            $basePath = SIRO_BASE_PATH;
        } else {
            $basePath = (string) getcwd();
        }
        $this->filePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $session): void
    {
        self::$instance = $session;
    }

    public function start(?string $sessionId = null): void
    {
        if ($this->started) return;

        $cookieSession = isset($_COOKIE['siro_session']) && is_string($_COOKIE['siro_session']) ? $_COOKIE['siro_session'] : null;
        $this->sessionId = $sessionId ?? ($cookieSession ?? $this->generateId());

        if (preg_match('/^[a-f0-9]{64}$/', $this->sessionId) !== 1) {
            $this->sessionId = $this->generateId();
        }

        if ($this->driver === self::DRIVER_REDIS) {
            $this->loadFromRedis();
        } else {
            $this->loadFromFile();
        }
        if ($this->data === []) {
            $this->sessionId = $this->generateId();
        }

        $idleTimeout = (int) Env::get('SESSION_IDLE_TIMEOUT', '1800');
        $lastActivity = isset($this->data['_last_activity']) && is_numeric($this->data['_last_activity']) ? (int) $this->data['_last_activity'] : 0;
        if ($lastActivity > 0 && (time() - $lastActivity) > $idleTimeout) {
            $this->destroy();
            $this->sessionId = $this->generateId();
            $this->data = [];
            $lastActivity = 0;
        }
        $this->data['_last_activity'] = time();

        $this->started = true;

        // Migrate flash from old to current
        $flashData = $this->data['_flash'] ?? [];
        $this->flash = is_array($flashData) ? (array) $flashData : [];
        unset($this->data['_flash']);
        $flashNext = $this->data['_flash_next'] ?? [];
        $this->data['_flash_next'] = is_array($flashNext) ? $flashNext : [];

        // Set session cookie
        if (!headers_sent()) {
            setcookie('siro_session', $this->sessionId, [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function save(): void
    {
        if (!$this->started) return;

        // Move flash_next to flash for next request
        $this->data['_flash'] = $this->data['_flash_next'] ?? [];
        unset($this->data['_flash_next']);

        if ($this->driver === self::DRIVER_REDIS) {
            $this->saveToRedis();
        } else {
            $this->saveToFile();
        }
    }

    public function getId(): string
    {
        return $this->sessionId;
    }

    public function setId(string $id): void
    {
        $this->sessionId = $id;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->flash = [];
        $this->started = false;

        $path = $this->filePath . DIRECTORY_SEPARATOR . $this->sessionId . '.json';
        if (is_file($path)) {
            unlink($path);
        }

        if (!headers_sent()) {
            setcookie('siro_session', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function regenerate(): void
    {
        $oldId = $this->sessionId;
        $this->sessionId = $this->generateId();

        // Save current data under new session ID
        if ($this->driver !== self::DRIVER_REDIS) {
            $this->saveToFile();
        } else {
            $this->saveToRedis();
        }

        // Remove old session file
        if ($this->driver !== self::DRIVER_REDIS) {
            $oldPath = $this->filePath . DIRECTORY_SEPARATOR . $oldId . '.json';
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        if (!headers_sent()) {
            setcookie('siro_session', $this->sessionId, [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function flash(string $key, mixed $value): void
    {
        if (!isset($this->data['_flash_next']) || !is_array($this->data['_flash_next'])) {
            $this->data['_flash_next'] = [];
        }
        /** @var array<string, mixed> $flashNext */
        $flashNext = $this->data['_flash_next'];
        $flashNext[$key] = $value;
        $this->data['_flash_next'] = $flashNext;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flash[$key] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return array_key_exists($key, $this->flash);
    }

    public function reflash(): void
    {
        $flashNext = $this->data['_flash_next'] ?? [];
        $this->data['_flash_next'] = array_merge(is_array($flashNext) ? $flashNext : [], $this->flash);
    }

    public function keep(string ...$keys): void
    {
        $flashNext = $this->data['_flash_next'] ?? [];
        if (!is_array($flashNext)) {
            $flashNext = [];
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->flash)) {
                $flashNext[$key] = $this->flash[$key];
            }
        }
        $this->data['_flash_next'] = $flashNext;
    }

    public function ageFlashData(): void
    {
        $this->data['_flash'] = [];
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Garbage collect expired file-based sessions.
     * Deletes session files older than $maxLifetime seconds (default 30 days).
     *
     * @return int Number of deleted session files
     */
    public static function gc(int $maxLifetime = 2592000): int
    {
        $basePath = '';
        if (defined('BASE_PATH') && is_string(BASE_PATH)) {
            $basePath = BASE_PATH;
        } elseif (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH)) {
            $basePath = SIRO_BASE_PATH;
        } else {
            $basePath = (string) getcwd();
        }
        $filePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';

        if (!is_dir($filePath)) {
            return 0;
        }

        $deleted = 0;
        $expireTime = time() - $maxLifetime;

        $files = glob($filePath . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $expireTime) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $data = $this->data;
        unset($data['_flash'], $data['_flash_next']);
        return $data;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function loadFromFile(): void
    {
        if (!is_dir($this->filePath)) {
            mkdir($this->filePath, 0775, true);
        }

        $path = $this->filePath . DIRECTORY_SEPARATOR . $this->sessionId . '.json';
        if (is_file($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $this->data = $decoded;
                }
            }
        }
    }

    private function saveToFile(): void
    {
        if (!is_dir($this->filePath)) {
            mkdir($this->filePath, 0775, true);
        }

        $path = $this->filePath . DIRECTORY_SEPARATOR . $this->sessionId . '.json';
        $encoded = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            file_put_contents($path, $encoded, LOCK_EX);
        }
    }

    private function loadFromRedis(): void
    {
        try {
            $redis = $this->getRedis();
            if ($redis !== null) {
                $data = $redis->get('session:' . $this->sessionId);
                if (is_string($data)) {
                    /** @var array<string, mixed>|null $decoded */
                    $decoded = json_decode($data, true);
                    if (is_array($decoded)) {
                        $this->data = $decoded;
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    private function saveToRedis(): void
    {
        try {
            $redis = $this->getRedis();
            if ($redis !== null) {
                $ttl = 86400 * 30;
                $encoded = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded !== false) {
                    $redis->setex('session:' . $this->sessionId, $ttl, $encoded);
                }
            }
        } catch (\Throwable) {
        }
    }

    private function getRedis(): ?\Redis
    {
        if (!class_exists(\Redis::class)) return null;

        if (self::$redisInstance !== null) return self::$redisInstance;

        try {
            $redis = new \Redis();
            $connected = $redis->connect(
                (string) Env::get('REDIS_HOST', '127.0.0.1'),
                (int) Env::get('REDIS_PORT', '6379'),
                (float) Env::get('REDIS_TIMEOUT', '0.2')
            );
            if (!$connected) return null;

            $password = (string) Env::get('REDIS_PASSWORD', '');
            if ($password !== '') $redis->auth($password);

            self::$redisInstance = $redis;
            return $redis;
        } catch (\Throwable) {
            return null;
        }
    }
}
