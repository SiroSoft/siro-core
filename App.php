<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;
use Throwable;

/**
 * Application entry point and lifecycle manager.
 *
 * Orchestrates the bootstrap sequence (env loading, security checks,
 * DB connection, cache init), route registration, and request dispatch.
 *
 * @package Siro\Core
 */
final class App
{
    private readonly string $basePath;
    public readonly Router $router;
    private bool $debug;
    private bool $showDebugTrace;
    private float $startedAt;

    /**
     * @param string $basePath Absolute path to the project root
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->router = new Router();
        $this->debug = false;
        $this->showDebugTrace = false;
        $this->startedAt = microtime(true);
    }

    /**
     * Bootstrap the application.
     *
     * Loads .env, initializes Logger, validates security config,
     * checks required PHP extensions, connects to the database,
     * boots the cache system, and registers core container bindings.
     *
     * @throws RuntimeException if APP_DEBUG is true in production
     * @throws RuntimeException if required PHP extensions are missing
     */
    public function boot(): void
    {
        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');
        Logger::boot($this->basePath);
        $this->validateSecurityConfig();
        $this->checkRequiredExtensions();

        $debug = Env::bool('APP_DEBUG', false);
        $appEnv = strtolower((string) Env::get('APP_ENV', 'production'));
        if ($appEnv === 'production' && $debug) {
            throw new RuntimeException('APP_DEBUG must be false in production environment.');
        }

        $this->debug = $debug && $appEnv !== 'production';
        $this->showDebugTrace = $debug && $appEnv !== 'production';

        if ($this->showDebugTrace) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
        }

        // Load config repository
        Config::load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        // Register core container bindings
        $container = Container::getInstance();
        $container->instance('app', $this);
        $container->instance(Router::class, $this->router);
        $container->singleton(Container::class, fn () => $container);

        // Load database config from Config repository
        $dbConfig = Config::get('database', []);
        if ($dbConfig === []) {
            $dbConfig = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        }
        Database::configure($dbConfig);
        Cache::boot($this->basePath);
        Lang::boot($this->basePath);
        Storage::boot();

        // Register default middleware aliases
        Router::registerMiddlewareAlias('auth', \Siro\Core\Middleware\AuthMiddleware::class);
        Router::registerMiddlewareAlias('throttle', \Siro\Core\Middleware\ThrottleMiddleware::class);
        Router::registerMiddlewareAlias('cors', \Siro\Core\Middleware\CorsMiddleware::class);
        Router::registerMiddlewareAlias('json', \Siro\Core\Middleware\JsonMiddleware::class);

        // Register default container bindings for auth
        $userModelClass = 'App\\Models\\User';
        if (class_exists($userModelClass)) {
            $container->bind('auth.provider', function () use ($userModelClass) {
                /** @var class-string $userModelClass */
                return new \Siro\Core\Auth\ModelUserProvider($userModelClass);
            });
        }
    }

    /**
     * Validate that JWT_SECRET is set and not a placeholder.
     * Auto-generates a secure secret if missing or weak.
     *
     * @throws RuntimeException if .env file is missing
     */
    private function validateSecurityConfig(): void
    {
        $jwtSecret = (string) Env::get('JWT_SECRET', '');
        $lower = strtolower($jwtSecret);
        $looksLikePlaceholder = str_contains($lower, 'change_this')
            || str_contains($lower, 'please_set')
            || str_contains($lower, 'your_secret');

        if ($jwtSecret === '' || strlen($jwtSecret) < 32 || $looksLikePlaceholder) {
            $this->autoGenerateJwtSecret();
        }
    }

    /**
     * Generate a random 64-char hex JWT secret and write it to .env.
     *
     * @throws RuntimeException if .env file not found
     */
    private function autoGenerateJwtSecret(): void
    {
        $envPath = $this->basePath . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envPath)) {
            throw new RuntimeException('.env file not found. Copy .env.example to .env first.');
        }

        $secret = bin2hex(random_bytes(32));
        $content = (string) file_get_contents($envPath);

        if (preg_match('/^JWT_SECRET=.*/m', $content) === 1) {
            $content = (string) preg_replace('/^JWT_SECRET=.*/m', 'JWT_SECRET=' . $secret, $content);
        } else {
            $content = rtrim($content) . PHP_EOL . 'JWT_SECRET=' . $secret . PHP_EOL;
        }

        file_put_contents($envPath, $content);
        Env::load($envPath);
    }

    /**
     * Verify required PHP extensions (pdo, json, mbstring) and
     * the PDO driver matching DB_CONNECTION.
     *
     * @throws RuntimeException if any required extension is missing
     */
    private function checkRequiredExtensions(): void
    {
        $required = ['pdo', 'json', 'mbstring'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        $dbConnection = strtolower((string) Env::get('DB_CONNECTION', 'mysql'));
        $pdoDriver = match ($dbConnection) {
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => 'pdo_mysql',
        };

        if (!extension_loaded($pdoDriver)) {
            $missing[] = $pdoDriver . " (for {$dbConnection})";
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required PHP extensions: ' . implode(', ', $missing) .
                '. Install them or update your php.ini configuration.'
            );
        }
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Load route definitions from a PHP file.
     *
     * The file receives $app and $router variables to register routes.
     *
     * @param string $routesFile Absolute path to the routes file (e.g., routes/api.php)
     */
    public function loadRoutes(string $routesFile): void
    {
        $app = $this;
        $router = $this->router;
        require $routesFile;
    }

    /**
     * Check if the application is in maintenance mode.
     *
     * @return array<string, mixed>|null null if not in maintenance, array of data if down
     */
    public static function isDown(): ?array
    {
        $file = defined('SIRO_BASE_PATH')
            ? SIRO_BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down'
            : (defined('BASE_PATH')
                ? BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down'
                : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down');
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Process the incoming HTTP request and send the response.
     *
     * Captures trace ID, timing, SQL queries (debug mode),
     * and logs every request. Handles ValidationException (422)
     * and generic Throwable (500) gracefully.
     *
     * Sets X-Siro-Trace-Id header on every response for production debugging.
     */
    public function run(): void
    {
        Response::enableDebug($this->debug);
        Cache::resetRequestState();
        $requestStartedAt = microtime(true);
        $method = 'GET';
        $path = '/';
        $status = 500;
        $traceId = bin2hex(random_bytes(8));
        Response::setRequestMeta($traceId, $requestStartedAt);

        try {
            $request = Request::fromGlobals();
            $method = $request->method();
            $path = $request->path();

            // Check maintenance mode
            $maintenance = self::isDown();
            if ($maintenance !== null) {
                $allowed = $maintenance['allow'] ?? [];
                $clientIp = $request->ip();
                if (!in_array($clientIp, $allowed, true)) {
                    $retry = max(0, (int) ($maintenance['retry'] ?? 60));
                    $resp = Response::error($maintenance['message'] ?? 'Under maintenance', 503);
                    $resp->header('Retry-After', (string) $retry);
                    $resp->header('X-Siro-Trace-Id', $traceId)->send();
                    $status = 503;
                    return;
                }
            }

            // Auto-detect locale from request header
            $this->detectLocale($request);

            $response = $this->router->dispatch($request);
            $status = $response->statusCode();
            $this->setDebugMeta();
            $response->header('X-Siro-Trace-Id', $traceId)->send();
        } catch (ValidationException $e) {
            Logger::error($e);
            $this->setDebugMeta();
            $errorResponse = $e->toResponse();
            $status = $errorResponse->statusCode();
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } catch (Throwable $e) {
            Logger::error($e);

            $errors = [];
            if ($this->showDebugTrace) {
                $errors = [
                    'type' => $e::class,
                    'trace' => $e->getTraceAsString(),
                ];
            }

            $this->setDebugMeta();
            $errorResponse = Response::error('Internal Server Error', 500, $errors);
            $status = $errorResponse->statusCode();
            $errorResponse->header('X-Siro-Trace-Id', $traceId)->send();
        } finally {
            $timeMs = (microtime(true) - $requestStartedAt) * 1000;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            Logger::request($method, $path, $status, $timeMs, $ip, $traceId, $userAgent);
            Logger::slowRequest($method, $path, $status, $timeMs);

            $traceData = [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'time_ms' => round($timeMs, 2),
                'ip' => $ip,
                'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
                'request_headers' => isset($request) ? $request->headers() : [],
                'request_body' => isset($request) ? mb_substr((string) json_encode($request->body(), JSON_UNESCAPED_UNICODE), 0, 2000) : '',
            ];

            if (isset($response)) {
                $traceData['response_body'] = mb_substr(
                    (string) json_encode($response->payload(), JSON_UNESCAPED_UNICODE),
                    0,
                    2000
                );
            }

            $authHeader = isset($request) ? $request->header('authorization', '') : '';
            if ($authHeader !== '') {
                $traceData['auth_header'] = $authHeader;
            }

            if ($this->debug) {
                $traceData['queries'] = Database::getCapturedQueries();
                $traceData['memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            }

            Logger::trace($traceId, $traceData);
            Database::resetCapturedQueries();
        }
    }

    /**
     * Detect locale from the request Accept-Language header
     * and set it on the Lang system.
     *
     * Priority: X-Locale header > Accept-Language > APP_LOCALE env
     */
    private function detectLocale(Request $request): void
    {
        // X-Locale header overrides everything (for testing)
        $xLocale = $request->header('x-locale', '');
        if ($xLocale !== '' && preg_match('/^[a-z]{2}([_-][a-z]{2})?$/i', $xLocale)) {
            Lang::setLocale(strtolower(substr($xLocale, 0, 2)));
            return;
        }

        // Parse Accept-Language header
        $acceptLang = $request->header('accept-language', '');
        if ($acceptLang !== '' && preg_match('/^([a-z]+)/i', $acceptLang, $matches)) {
            $locale = strtolower($matches[1]);
            $langDir = Lang::basePath() . DIRECTORY_SEPARATOR . $locale;
            if (is_dir($langDir)) {
                Lang::setLocale($locale);
            }
        }
    }

    /**
     * Attach debug metadata (execution time, memory, cache status)
     * to the response when debug mode is enabled.
     */
    private function setDebugMeta(): void
    {
        if (!$this->debug) {
            return;
        }

        $executionTimeMs = (microtime(true) - $this->startedAt) * 1000;
        $memoryUsageMb = memory_get_peak_usage(true) / 1024 / 1024;

        Response::setDebugMeta([
            'execution_time_ms' => round($executionTimeMs, 2),
            'memory_usage_mb' => round($memoryUsageMb, 2),
            'cache' => Cache::requestStatus(),
        ]);
    }
}
