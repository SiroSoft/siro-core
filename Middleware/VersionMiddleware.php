<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;

/**
 * API versioning via Accept header.
 *
 * Clients specify version: Accept: application/vnd.siro.v2+json
 * Sets `request->version` and `request->versioned_path` for controller use.
 * Falls back to latest version if not specified.
 *
 * @package Siro\Core
 */
final class VersionMiddleware implements MiddlewareInterface
{
    private const VERSION_PATTERN = '/application\/vnd\.siro\.v(\d+)\+json/';

    /** @var array<int, string> Registered versions with their path prefixes */
    private static array $versions = [];

    /** @var array<string, callable|string|array<int, mixed>> Versioned route overrides */
    private static array $overrides = [];

    private static int $latestVersion = 1;

    public static function register(int $version, string $pathPrefix = ''): void
    {
        self::$versions[$version] = $pathPrefix;
        self::$latestVersion = max(self::$latestVersion, $version);
    }

    /**
     * Register a route override for a specific version.
     * Example: VersionMiddleware::override(2, 'GET', '/users', V2UserController::class);
     */
    /** @param callable|string|array<int, mixed> $handler */
    public static function override(int $version, string $method, string $path, callable|string|array $handler): void
    {
        self::$overrides["{$version}:{$method}:{$path}"] = $handler;
    }

    public static function getVersion(Request $request): int
    {
        $header = (string) $request->header('accept', '');
        if (preg_match(self::VERSION_PATTERN, $header, $m)) {
            $version = (int) $m[1];
            if (isset(self::$versions[$version])) {
                return $version;
            }
        }
        return self::$latestVersion;
    }

    /** @return callable|string|array<int, mixed>|null */
    public static function resolveOverride(int $version, string $method, string $path): mixed
    {
        $key = "{$version}:{$method}:{$path}";
        return self::$overrides[$key] ?? null;
    }

    public function handle(Request $request, callable $next): mixed
    {
        $version = self::getVersion($request);
        $request->setVersion($version);

        // Check for version override
        $override = self::resolveOverride($version, $request->method(), $request->path());
        if ($override !== null) {
            // Replace handler by storing in request for Router to pick up
            $request->setVersionedHandler($override);
        }

        $response = $next($request);

        if ($response instanceof Response) {
            $response->header('X-API-Version', (string) $version);
        }

        return $response;
    }
}
