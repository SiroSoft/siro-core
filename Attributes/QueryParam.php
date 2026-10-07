<?php

declare(strict_types=1);

namespace Siro\Core\Attributes;

use Attribute;

/**
 * Declare a query-string parameter on a controller method.
 *
 * Repeatable. Feeds the OpenAPI generator (parameters section).
 *
 * Usage:
 *   #[QueryParam('page', type: 'integer', description: 'Page number')]
 *   #[QueryParam('search', required: false)]
 *   public function index(Request $request): Response { ... }
 *
 * @package Siro\Core\Attributes
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class QueryParam
{
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = false,
    ) {
    }
}
