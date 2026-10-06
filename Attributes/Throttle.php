<?php

declare(strict_types=1);

namespace Siro\Core\Attributes;

use Attribute;

/**
 * Rate-limit a controller class or method with typed values.
 *
 * Typed alternative to the 'throttle:60,1' string form; desugars to the same
 * ThrottleMiddleware entry, so behavior is identical.
 *
 * Usage:
 *   #[Throttle(10, minutes: 1)]
 *   public function store(Request $request): Response { ... }
 *
 * @package Siro\Core\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Throttle
{
    public function __construct(
        public readonly int $max,
        public readonly int $minutes = 1,
    ) {
    }
}
