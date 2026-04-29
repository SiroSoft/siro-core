<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;
use Throwable;

final class App
{
    private readonly string $basePath;
    public readonly Router $router;
    private bool $debug;
    private bool $showDebugTrace;
    private float $startedAt;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->router = new Router();
        $this->debug = false;
        $this->showDebugTrace = false;
        $this->startedAt = microtime(true);
    }

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

        /** @var array<string, mixed> $dbConfig */
        $dbConfig = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        Database::configure($dbConfig);
        Cache::boot($this->basePath);
    }

    private function validateSecurityConfig(): void
    {
        $jwtSecret = (string) Env::get('JWT_SECRET', '');
        $lower = strtolower($jwtSecret);
        $looksLikePlaceholder = str_contains($lower, 'change_this')
            || str_contains($lower, 'please_set')
            || str_contains($lower, 'your_secret');

        if ($jwtSecret === '' || strlen($jwtSecret) < 32 || $looksLikePlaceholder) {
            // Auto-generate JWT secret if placeholder detected
            $this->autoGenerateJwtSecret();
        }
    }

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
        
        // Reload env to pick up new value
        Env::load($envPath);
    }

    private function checkRequiredExtensions(): void
    {
        $required = ['pdo', 'json', 'mbstring'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        // Check PDO drivers based on DB_CONNECTION
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

    public function loadRoutes(string $routesFile): void
    {
        $app = $this;
        $router = $this->router;
        require $routesFile;
    }

    public function run(): void
    {
        Response::enableDebug($this->debug);
        Cache::resetRequestState();
        $requestStartedAt = microtime(true);
        $method = 'GET';
        $path = '/';
        $status = 500;
        $traceId = bin2hex(random_bytes(8));

        try {
            $request = Request::fromGlobals();
            $method = $request->method();
            $path = $request->path();
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
                'request_headers' => $request->headers(),
                'request_body' => mb_substr((string) json_encode($request->body(), JSON_UNESCAPED_UNICODE), 0, 2000),
            ];

            // Capture response body from the response
            if (isset($response)) {
                $traceData['response_body'] = mb_substr(
                    (string) json_encode($response->payload(), JSON_UNESCAPED_UNICODE),
                    0,
                    2000
                );
            }

            // Capture auth header separately for replay
            $authHeader = $request->header('authorization', '');
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
