<?php

declare(strict_types=1);

namespace Siro\Core;

use Attribute;

/**
 * Inject a scalar/config value into a constructor parameter.
 *
 * The container resolves the key in order: bindValue() entries, contextual
 * and global bindings (closures are invoked), then Env. Values from Env are
 * strings and are coerced to the parameter type (int/float/bool) when the
 * string is a valid representation; otherwise resolution falls back to the
 * parameter default (or throws for required params).
 *
 * Usage:
 *   public function __construct(
 *       #[Inject('config.jwt.ttl')] int $ttl = 3600,
 *       #[Inject('services.payment.key')] string $apiKey = '',
 *   ) {}
 *
 * Register values in a service provider or bootstrap:
 *   $container->bindValue('config.jwt.ttl', 7200);
 *
 * @package Siro\Core
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(
        public readonly string $key
    ) {
    }
}
