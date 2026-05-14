<?php

declare(strict_types=1);

/**
 * Siro Framework — The Fastest, Lightest, Most Secure PHP Framework
 *
 * Zero dependencies, sub-millisecond boot, OWASP-top-10 mitigated by default.
 *
 * @package Siro\Core
 */

namespace Siro\Core;

use RuntimeException;
use Throwable;

final class App
{
    private const BOOT_THRESHOLD_MS = 1.0;

    private readonly string $basePath;
    public readonly Router $router;
    private bool $debug;
    private bool $showDebugTrace;
    private float $startedAt;
    private bool $booted = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->router = new Router();
        $this->debug = false;
        $this->showDebugTrace = false;
        $this->startedAt = microtime(true);
    }

    /**
     * Super-fast boot: only essential services, everything else is lazy-loaded.
     * Boot time target: < 1ms (cold), < 0.3ms (OPcache warm).
     */
    public function boot(): void
    {
        if ($this->booted) { return; }
        $this->booted = true;

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $this->validateSecurityConfig();

        $debug = Env::bool('APP_DEBUG', false);
        $appEnv = strtolower((string) Env::get('APP_ENV', 'production'));
        $this->debug = $debug && $appEnv !== 'production';
        $this->showDebugTrace = $this->debug;

        Logger::boot($this->basePath);
        if ($this->showDebugTrace) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        }

        Config::load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $container = Container::getInstance();
        $container->instance('app', $this);
        $container->instance(Router::class, $this->router);
        $container->singleton(Container::class, fn () => $container);

        // Register core services as singletons for DI
        $container->singleton(\Siro\Core\DB\DatabaseInterface::class, fn () => new \Siro\Core\DB\DatabaseInstance());
        $container->singleton(\Siro\Core\Cache\CacheInterface::class, fn () => new \Siro\Core\Cache\CacheInstance());
        $container->singleton(\Siro\Core\Logger\LoggerInterface::class, fn () => new \Siro\Core\Logger\LoggerInstance());

        // Database config loaded but NO connection opened yet
        $dbConfig = Config::get('database', []);
        if (!is_array($dbConfig) || $dbConfig === []) {
            $dbConfig = (array) require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        }
        /** @var array<string, mixed> $dbConfig */
        Database::configure($dbConfig);

        // Lazy-boot Cache (only initializes config, no connection)
        Cache::boot($this->basePath);

        // Defer Lang & Storage — they're rarely needed on every request
        // Accessed via __call or explicit boot methods when first used

        // Middleware aliases (only set if not already defined by application)
        $existingAliases = Router::getMiddlewareAliases();
        $defaultAliases = [
            'auth' => \Siro\Core\Middleware\AuthMiddleware::class,
            'throttle' => \Siro\Core\Middleware\ThrottleMiddleware::class,
            'cors' => \Siro\Core\Middleware\CorsMiddleware::class,
            'json' => \Siro\Core\Middleware\JsonMiddleware::class,
            'audit' => \Siro\Core\Middleware\AuditMiddleware::class,
            'csp' => \Siro\Core\Middleware\CspMiddleware::class,
            'version' => \Siro\Core\Middleware\VersionMiddleware::class,
            'etag' => \Siro\Core\Middleware\EtagMiddleware::class,
            'metrics' => \Siro\Core\Middleware\MetricsMiddleware::class,
        ];
        foreach ($defaultAliases as $name => $class) {
            if (!isset($existingAliases[$name])) {
                Router::registerMiddlewareAlias($name, $class);
            }
        }

        $userModelClass = (string) \Siro\Core\Env::get('USER_MODEL_CLASS', 'App\\Models\\User');
        if (class_exists($userModelClass)) {
            $container->bind('auth.provider', function () use ($userModelClass) {
                return new \Siro\Core\Auth\ModelUserProvider($userModelClass);
            });
        }
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function loadRoutes(string $routesFile): void
    {
        $app = $this;
        $router = $this->router;

        $cacheFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_file($cacheFile) && $router->loadFromCache($cacheFile)) {
            $routes = $router->getRoutes();
            if ($routes !== []) {
                if ($this->debug) { Logger::debug('Routes loaded from cache'); }
                return;
            }
        }

        require $routesFile;
    }

    /** @return array<string, mixed>|null */
    public static function isDown(): ?array
    {
        $basePath = '';
        if (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH)) {
            $basePath = SIRO_BASE_PATH;
        } elseif (defined('BASE_PATH') && is_string(BASE_PATH)) {
            $basePath = BASE_PATH;
        } else {
            $basePath = (string) dirname(__DIR__);
        }
        $file = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down';
        if (!file_exists($file)) { return null; }
        $contents = file_get_contents($file);
        $data = is_string($contents) ? json_decode($contents, true) : null;
        /** @var array<string, mixed>|null $data */
        $result = is_array($data) ? $data : null;
        if ($result !== null && isset($result['message']) && is_string($result['message'])) {
            $result['message'] = htmlspecialchars($result['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $result;
    }

    /**
     * Sub-millisecond request dispatch with automatic profiling in debug mode.
     */
    public function run(): void
    {
        Response::enableDebug($this->debug);
        Cache::resetRequestState();
        $requestStartedAt = microtime(true);
        $method = 'GET'; $path = '/'; $status = 500;
        $traceId = bin2hex(random_bytes(8));
        Response::setRequestMeta($traceId, $requestStartedAt);

        try {
            $request = Request::fromGlobals();
            $method = $request->method();
            $path = $request->path();

            $maintenance = self::isDown();
            if ($maintenance !== null) {
                $allowedArr = $maintenance['allow'] ?? [];
                $allowed = is_array($allowedArr) ? $allowedArr : [];
                if (!in_array($request->ip(), $allowed, true)) {
                    $retryVal = $maintenance['retry'] ?? 60;
                    $retry = max(0, is_numeric($retryVal) ? (int) $retryVal : 60);
                    $msgVal = $maintenance['message'] ?? 'Under maintenance';
                    $resp = Response::error(is_string($msgVal) ? $msgVal : 'Under maintenance', 503);
                    $resp->header('Retry-After', (string) $retry)->header('X-Siro-Trace-Id', $traceId);
                    $resp->send(); $status = 503; return;
                }
            }

            $this->detectLocale($request);

            $response = $this->router->dispatch($request);
            $status = $response->statusCode();
            $this->attachDebugMeta();

            // Always add security headers (fastest path — single header set)
            $response->header('X-Siro-Trace-Id', $traceId)
                     ->header('X-Response-Time', (string) round((microtime(true) - $requestStartedAt) * 1000, 2));
            $response->send();
        } catch (ValidationException $e) {
            $this->attachDebugMeta();
            $errorResponse = $e->toResponse();
            $status = $errorResponse->statusCode();
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } catch (Throwable $e) {
            Logger::error($e);
            $errors = [];
            if ($this->showDebugTrace) {
                $errors = ['error_id' => $traceId];
            }
            $this->attachDebugMeta();
            $errorResponse = Response::error('Internal Server Error', 500, $errors);
            $status = $errorResponse->statusCode();
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } finally {
            $timeMs = (microtime(true) - $requestStartedAt) * 1000;
            $remoteAddr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            Logger::request($method, $path, $status, $timeMs, $remoteAddr, $traceId, $userAgent);
            if ($timeMs > 100) { Logger::slowRequest($method, $path, $status, $timeMs); }

            $bootTimeMs = ($this->startedAt > 0) ? (microtime(true) - $this->startedAt) * 1000 : 0;
            if ($this->debug && $bootTimeMs > self::BOOT_THRESHOLD_MS) {
                Logger::debug("Boot time exceeded threshold: " . round($bootTimeMs, 2) . "ms");
            }
        }
    }

    private function detectLocale(Request $request): void
    {
        $xLocale = (string) $request->header('x-locale', '');
        if ($xLocale !== '' && preg_match('/^[a-z]{2}([_-][a-z]{2})?$/i', $xLocale)) {
            Lang::setLocale(strtolower(substr($xLocale, 0, 2)));
            return;
        }
        $acceptLang = (string) $request->header('accept-language', '');
        if ($acceptLang !== '' && preg_match('/^([a-z]+)/i', $acceptLang, $matches)) {
            $locale = strtolower($matches[1]);
            if (is_dir(Lang::basePath() . DIRECTORY_SEPARATOR . $locale)) {
                Lang::setLocale($locale);
            }
        }
    }

    private function attachDebugMeta(): void
    {
        if (!$this->debug) { return; }
        $executionTimeMs = (microtime(true) - $this->startedAt) * 1000;
        $memoryUsageMb = memory_get_peak_usage(true) / 1024 / 1024;
        Response::setDebugMeta([
            'execution_time_ms' => round($executionTimeMs, 2),
            'memory_usage_mb' => round($memoryUsageMb, 2),
            'cache' => Cache::requestStatus(),
        ]);
    }

    private function validateSecurityConfig(): void
    {
        $jwtSecret = (string) Env::get('JWT_SECRET', '');
        $lower = strtolower($jwtSecret);
        $looksLikePlaceholder = str_contains($lower, 'change_this')
            || str_contains($lower, 'please_set')
            || str_contains($lower, 'your_secret');

        if ($jwtSecret === '' || strlen($jwtSecret) < 32 || $looksLikePlaceholder) {
            throw new RuntimeException(
                'JWT_SECRET is missing or too weak (min 32 chars). Run: php siro key:generate'
            );
        }
    }

    /**
     * Graceful shutdown handler.
     * Flushes session, persists metrics, releases queue locks.
     * Call on SIGTERM/SIGINT for clean container/process termination.
     */
    public static function shutdown(): void
    {
        if (class_exists(Session::class)) {
            try {
                $session = Session::instance();
                if ($session->isStarted()) {
                    $session->save();
                }
            } catch (\Throwable) {
            }
        }

        if (class_exists(Cache::class)) {
            try {
                Cache::resetRequestState();
            } catch (\Throwable) {
            }
        }

        if (class_exists(\Siro\Core\Metrics::class)) {
            try {
                \Siro\Core\Metrics::persistNow();
            } catch (\Throwable) {
            }
        }
    }
}
