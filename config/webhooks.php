<?php

use Siro\Core\Env;

return [
    // Endpoints that receive webhook events. Each entry:
    //   url    string        Destination endpoint (http/https)
    //   secret string        HMAC secret for signing (falls back to default_secret)
    //   events list<string>  Events this endpoint receives (empty = all events)
    'endpoints' => [],

    // Fallback secret for endpoints without their own secret.
    // Prefer the WEBHOOK_SECRET env var over committing a secret here.
    'default_secret' => Env::get('WEBHOOK_SECRET', ''),

    // Queue retries per delivery (Queue applies exponential backoff
    // 5s, 10s, 20s, ... capped at 300s, then dead-letters to failed_jobs).
    'max_attempts' => 3,

    // HTTP timeout per delivery attempt, in seconds.
    'timeout' => 10,

    // How old a signed delivery may be before the receiver rejects it
    // as a replay (seconds). Must match the receiver's tolerance.
    'tolerance_seconds' => 300,
];
