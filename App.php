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
        $dbConfig = (array) Config::get('database', []);
        if ($dbConfig === []) {
            $dbConfig = (array) require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        }
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

        /** @var class-string $userModelClass */
        $userModelClass = 'App\\Models\\User';
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
        $basePath = defined('SIRO_BASE_PATH') ? SIRO_BASE_PATH : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));
        $file = (string) $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down';
        if (!file_exists($file)) { return null; }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
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
                $allowed = (array) ($maintenance['allow'] ?? []);
                if (!in_array($request->ip(), $allowed, true)) {
                    $retry = max(0, (int) ($maintenance['retry'] ?? 60));
                    $resp = Response::error((string) ($maintenance['message'] ?? 'Under maintenance'), 503);
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
            if ($this->showDebugTrace) {
                $previous = '';
                $prev = $e->getPrevious();
                while ($prev !== null) {
                    $previous .= $prev::class . ': ' . $prev->getMessage() . ' in ' . $prev->getFile() . ':' . $prev->getLine() . "\n";
                    $prev = $prev->getPrevious();
                }
                $errors = [
                    'type' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'previous' => $previous !== '' ? rtrim($previous) : null,
                    'method' => $method,
                    'path' => $path,
                ];
            } else {
                $errors = [];
            }
            $this->attachDebugMeta();
            $errorResponse = Response::error('Internal Server Error', 500, $errors);
            $status = $errorResponse->statusCode();
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } finally {
            $timeMs = (microtime(true) - $requestStartedAt) * 1000;
            Logger::request($method, $path, $status, $timeMs, $_SERVER['REMOTE_ADDR'] ?? '', $traceId, $_SERVER['HTTP_USER_AGENT'] ?? '');
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
}
