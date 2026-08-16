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
use Siro\Core\Debug\TraceData;

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
     * Boot time: ~0.5ms (Linux + OPcache), ~2.4ms (Windows, cold).
     * Windows is slower due to filesystem I/O (mkdir, file scanning).
     * Measured via scripts/bench-boot.php. See BENCHMARK.md.
     */
    public function boot(): void
    {
        if ($this->booted) { return; }
        $this->booted = true;

        \Siro\Core\Env::reset();
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
            if (class_exists(Database::class)) {
                Database::enableQueryCapture(true);
            }
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

        $this->discoverPackageProviders();

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

        // Set default middleware priority (lower runs first)
        Router::setMiddlewarePriorities([
            'cors' => 10,
            'version' => 20,
            'json' => 30,
            'csp' => 40,
            'etag' => 50,
            'auth' => 60,
            'api_key' => 65,
            'throttle' => 70,
            'audit' => 80,
            'metrics' => 90,
            'idempotency' => 100,
            'csrf' => 110,
        ]);

        $userModelClass = (string) \Siro\Core\Env::get('USER_MODEL_CLASS', 'App\\Models\\User');
        if (class_exists($userModelClass) && is_subclass_of($userModelClass, \Siro\Core\Model::class)) {
            $container->bind('auth.provider', function () use ($userModelClass) {
                return new \Siro\Core\Auth\ModelUserProvider($userModelClass);
            });
        }
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function discoverPackageProviders(): void
    {
        $installedFile = $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (!file_exists($installedFile)) {
            return;
        }

        $contents = file_get_contents($installedFile);
        if ($contents === false) {
            return;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return;
        }

        foreach ($data['packages'] as $package) {
            if (!is_array($package)) {
                continue;
            }
            $extra = $package['extra'] ?? null;
            if (!is_array($extra)) {
                continue;
            }
            $siro = $extra['siro'] ?? null;
            if (!is_array($siro)) {
                continue;
            }
            $providers = $siro['providers'] ?? null;
            if (!is_array($providers)) {
                continue;
            }

            foreach ($providers as $providerClass) {
                if (!is_string($providerClass) || $providerClass === '' || !class_exists($providerClass)) {
                    continue;
                }
                $provider = new $providerClass();
                if (method_exists($provider, 'register')) {
                    $provider->register($this);
                }
            }
        }
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
        $request = null;
        // W3C Trace Context: accept incoming, propagate outgoing
        $incomingTraceparent = isset($_SERVER['HTTP_TRACEPARENT']) && is_string($_SERVER['HTTP_TRACEPARENT']) ? $_SERVER['HTTP_TRACEPARENT'] : '';
        $traceIdRaw = bin2hex(random_bytes(16));
        $traceId = 'siro_' . $traceIdRaw;
        $spanId = bin2hex(random_bytes(8));

        if (preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/', $incomingTraceparent, $m) === 1) {
            $traceIdRaw = $m[1];
            $traceId = 'siro_' . $traceIdRaw;
            $traceparent = sprintf('00-%s-%s-01', $traceIdRaw, $spanId);
        } else {
            $traceparent = sprintf('00-%s-%s-01', $traceIdRaw, $spanId);
        }

        Response::setRequestMeta($traceId, $requestStartedAt);

        // Reset & enrich trace data
        TraceData::reset();
        if ($this->debug) {
            $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
            /** @var array<string, string> $allHeaders */
            TraceData::setRequestHeaders($allHeaders);
        }

        try {
            $request = Request::fromGlobals();
            $method = $request->method();
            $path = $request->path();

            // Capture request body for debug AFTER Request::fromGlobals()
            // php://input is read-once, so we must not consume it before fromGlobals()
            if ($this->debug) {
                $rawBody = Request::getRawBodyCache();
                TraceData::setRequestBody($rawBody ?? '');
            }

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
            if ($this->debug) {
                TraceData::setResponseBody((string) json_encode($response->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            // Always add security and trace headers
            $response->header('X-Siro-Trace-Id', $traceId)
                     ->header('X-Response-Time', (string) round((microtime(true) - $requestStartedAt) * 1000, 2))
                     ->header('traceparent', $traceparent);
            $response->send();
        } catch (ValidationException $e) {
            $this->attachDebugMeta();
            $errorResponse = $e->toResponse();
            $status = $errorResponse->statusCode();
            if ($this->debug) {
                TraceData::setResponseBody((string) json_encode($errorResponse->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                TraceData::setException($e::class, $e->getMessage());
            }
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } catch (ModelNotFoundException $e) {
            $this->attachDebugMeta();
            $status = 404;
            $errorResponse = Response::error($e->getMessage(), 404);
            if ($this->debug) {
                TraceData::setResponseBody((string) json_encode($errorResponse->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                TraceData::setException($e::class, $e->getMessage());
            }
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } catch (Throwable $e) {
            Logger::error($e);
            $errors = [];
            if ($this->showDebugTrace) {
                $errors = ['error_id' => $traceId];
            }
            if ($request !== null && class_exists(\App\Exceptions\Handler::class) && is_subclass_of(\App\Exceptions\Handler::class, \Siro\Core\ExceptionHandlerInterface::class)) {
                /** @var Response $errorResponse */
                $errorResponse = \App\Exceptions\Handler::handle($e, $request);
            } else {
                $this->attachDebugMeta();
                $errorResponse = Response::error('Internal Server Error', 500, $errors);
            }
            $status = $errorResponse->statusCode();
            if ($this->debug) {
                TraceData::setResponseBody((string) json_encode($errorResponse->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                TraceData::setException($e::class, $e->getMessage());
            }
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } finally {
            $timeMs = (microtime(true) - $requestStartedAt) * 1000;
            $remoteAddr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            Logger::request($method, $path, $status, $timeMs, $remoteAddr, $traceId, $userAgent);
            if ($timeMs > 100) { Logger::slowRequest($method, $path, $status, $timeMs); }

            // Write trace file for why/replay commands
            if ($this->debug) {
                $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost:8080';
                $traceData = [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'time_ms' => round($timeMs, 2),
                    'trace_id' => $traceId,
                    'ip' => $remoteAddr,
                    'user_agent' => $userAgent,
                    'host' => $host,
                    'timestamp' => date('c'),
                ];

                // Merge enriched trace data (middleware, queries, body, exception)
                foreach (TraceData::getAll() as $key => $value) {
                    $traceData[$key] = $value;
                }

                // Redact PII from trace data
                if (isset($traceData['request_headers']) && is_array($traceData['request_headers']) && isset($traceData['request_headers']['authorization'])) {
                    $traceData['request_headers']['authorization'] = '***REDACTED***';
                }
                if (isset($traceData['auth_header'])) {
                    $traceData['auth_header'] = '***REDACTED***';
                }
                if (isset($traceData['cookie'])) {
                    $traceData['cookie'] = '***REDACTED***';
                }
                if (isset($traceData['request_body']) && is_string($traceData['request_body'])) {
                    $sensitiveKeys = ['password', 'token', 'secret', 'key', 'otp', 'code', 'authorization', 'access_token', 'refresh_token', 'api_key', 'session_id'];
                    $body = json_decode($traceData['request_body'], true);
                    if (is_array($body)) {
                        $body = self::redactSensitiveKeys($body, $sensitiveKeys);
                        $traceData['request_body'] = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
                    } else {
                        $redacted = preg_replace('/(password|token|secret|key|otp|code|access_token|refresh_token|api_key|session_id)=([^&\s]+)/i', '$1=***REDACTED***', $traceData['request_body']);
                        // Also redact XML sensitive fields
                        $traceData['request_body'] = (string) preg_replace('/<(\/?)(' . implode('|', $sensitiveKeys) . ')([^>]*)>(.*?)<\/\2>/i', '<$1$2$3>***REDACTED***</$2>', $redacted ?? $traceData['request_body']);
                    }
                }

                // Merge captured SQL queries from Database
                if (class_exists(Database::class)) {
                    $captured = Database::getCapturedQueries();
                    if ($captured !== []) {
                        $traceData['queries'] = array_map(function (array $q): array {
                            return [
                                'sql' => $q['sql'],
                                'time_ms' => $q['time_ms'],
                                'rows' => $q['rows'],
                            ];
                        }, $captured);
                    }
                }

                Logger::trace($traceId, $traceData);
            }

            Model::clearIdentityMap();
            $bootTimeMs = ($this->startedAt > 0) ? (microtime(true) - $this->startedAt) * 1000 : 0;
            if ($this->debug && $bootTimeMs > self::BOOT_THRESHOLD_MS) {
                Logger::debug("Boot time exceeded threshold: " . round($bootTimeMs, 2) . "ms");
            }
        }
    }

    /**
     * @param array<mixed, mixed> $data
     * @param array<int, string> $sensitiveKeys
     * @return array<mixed, mixed>
     */
    private static function redactSensitiveKeys(array $data, array $sensitiveKeys): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = self::redactSensitiveKeys($value, $sensitiveKeys);
            }
        }
        return $data;
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
                'JWT_SECRET is missing or too weak. Must be at least 32 characters.'
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
