<?php

/**
 * OAuth2 configuration (siro-core v1.5.0, direction B: league/oauth2-server).
 *
 * The library itself is optional (`composer suggest`): this file only holds
 * defaults. Keys come from the environment — generate them with:
 *
 *   php siro make:oauth-keys
 *
 * which writes OAUTH_PRIVATE_KEY_PATH / OAUTH_PUBLIC_KEY_PATH /
 * OAUTH_ENCRYPTION_KEY into .env.
 */

declare(strict_types=1);

return [
    // Scopes an app may grant. A client with an empty allowlist may use all
    // of these; a client with its own allowlist is restricted to it.
    'scopes' => ['read', 'write'],

    // Scope granted when the request asks for none.
    'default_scope' => 'read',

    // Token lifetimes (seconds).
    'access_token_ttl' => 3600,
    'refresh_token_ttl' => 2592000, // 30 days
    'auth_code_ttl' => 600, // 10 minutes
];
