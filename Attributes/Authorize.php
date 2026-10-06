<?php

declare(strict_types=1);

namespace Siro\Core\Attributes;

use Attribute;

/**
 * Require a Gate ability on a controller method (repeatable: all must pass).
 *
 * Enforced at dispatch through AuthorizeMiddleware against Gate, using
 * $request->user(). Denial throws AuthorizationException (403 forbidden).
 *
 * Usage:
 *   #[Authorize('products.create')]
 *   public function store(Request $request): Response { ... }
 *
 * @package Siro\Core\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Authorize
{
    public function __construct(
        public readonly string $ability,
    ) {
    }
}
