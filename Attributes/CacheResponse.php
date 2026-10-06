<?php

declare(strict_types=1);

namespace Siro\Core\Attributes;

use Attribute;

/**
 * Cache GET responses of a controller class or method for $ttl seconds.
 *
 * Equivalent to ->cache($ttl) on the route definition.
 *
 * Usage:
 *   #[CacheResponse(60)]
 *   public function index(Request $request): Response { ... }
 *
 * @package Siro\Core\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class CacheResponse
{
    public function __construct(
        public readonly int $ttl = 60,
    ) {
    }
}
