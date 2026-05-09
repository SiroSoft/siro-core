<?php

/**
 * Opcache Preloading Script
 *
 * This file is loaded via opcache.preload in php.ini or opcache.preload_file via Apache/Nginx config.
 *
 * Preloading provides ~10-20% performance improvement by compiling all framework
 * classes into shared memory (opcache) at server startup.
 *
 * Usage:
 *   1. Add to php.ini: opcache.preload=/path/to/siro-core/preload.php
 *   2. Or in Apache: php_admin_value opcache.preload /path/to/siro-core/preload.php
 *   3. Or in Nginx: fastcgi_param PHP_VALUE "opcache.preload=/path/to/siro-core/preload.php"
 *
 * After changes, restart PHP-FPM/Apache/Nginx to apply.
 */

// Get the directory of this preload script
$basePath = dirname(__DIR__);

// Ensure SIRO_BASE_PATH is defined for framework
if (!defined('SIRO_BASE_PATH')) {
    define('SIRO_BASE_PATH', $basePath);
}

// Files to preload - core framework classes
$files = [
    // Core
    __DIR__ . '/App.php',
    __DIR__ . '/Router.php',
    __DIR__ . '/Request.php',
    __DIR__ . '/Response.php',
    __DIR__ . '/Config.php',
    __DIR__ . '/Env.php',
    __DIR__ . '/Logger.php',

    // Database
    __DIR__ . '/Database.php',
    __DIR__ . '/Schema.php',
    __DIR__ . '/DB/QueryBuilder.php',
    __DIR__ . '/DB/ModelQueryBuilder.php',
    __DIR__ . '/DB/Blueprint.php',
    __DIR__ . '/DB/Column.php',

    // Auth
    __DIR__ . '/Auth/JWT.php',
    __DIR__ . '/Auth/AuthGuard.php',
    __DIR__ . '/Auth/ApiKey.php',
    __DIR__ . '/Auth/Idempotency.php',

    // Model
    __DIR__ . '/Model.php',

    // Cache & Storage
    __DIR__ . '/Cache.php',
    __DIR__ . '/Storage.php',

    // Middleware
    __DIR__ . '/Middleware/AuthMiddleware.php',
    __DIR__ . '/Middleware/ThrottleMiddleware.php',
    __DIR__ . '/Middleware/CorsMiddleware.php',
    __DIR__ . '/Middleware/JsonMiddleware.php',
    __DIR__ . '/Middleware/ApiKeyMiddleware.php',
    __DIR__ . '/Middleware/IdempotencyMiddleware.php',

    // Utilities
    __DIR__ . '/Str.php',
    __DIR__ . '/Arr.php',
    __DIR__ . '/Hash.php',
    __DIR__ . '/Encrypt.php',
    __DIR__ . '/Container.php',
    __DIR__ . '/Lang.php',
    __DIR__ . '/Session.php',
    __DIR__ . '/Queue.php',
    __DIR__ . '/RateLimiter.php',
    __DIR__ . '/UploadedFile.php',
    __DIR__ . '/ValidationException.php',

    // Relations
    __DIR__ . '/DB/Relations/HasOne.php',
    __DIR__ . '/DB/Relations/HasMany.php',
    __DIR__ . '/DB/Relations/BelongsTo.php',
    __DIR__ . '/DB/Relations/BelongsToMany.php',

    // Commands (optional - reduces CLI startup time)
    __DIR__ . '/Console.php',
    __DIR__ . '/Commands/CommandSupport.php',
];

// Preload each file
foreach ($files as $file) {
    if (is_file($file)) {
        opcache_get_status(true); // Ensure opcache is enabled
        require_once $file;
    }
}

// Preload app models if exists
$modelsPath = $basePath . '/app/Models';
if (is_dir($modelsPath)) {
    foreach (glob($modelsPath . '/*.php') as $file) {
        if (is_file($file)) {
            require_once $file;
        }
    }
}

// Preload app controllers if exists
$controllersPath = $basePath . '/app/Controllers';
if (is_dir($controllersPath)) {
    foreach (glob($controllersPath . '/*.php') as $file) {
        if (is_file($file)) {
            require_once $file;
        }
    }
}

echo "SiroPHP preloaded successfully.\n";