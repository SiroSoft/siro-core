<?php

declare(strict_types=1);

return [
    'scopes' => ['read', 'write'],
    'default_scope' => 'read',
    'access_token_ttl' => 3600,
    'refresh_token_ttl' => 2592000,
    'auth_code_ttl' => 600,
];
