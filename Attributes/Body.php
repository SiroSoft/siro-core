<?php

declare(strict_types=1);

namespace Siro\Core\Attributes;

use Attribute;

/**
 * Declare a JSON request-body field on a controller method.
 *
 * Repeatable. Feeds the OpenAPI generator (requestBody schema) and documents
 * the validation contract next to the handler instead of in a separate file.
 *
 * Usage:
 *   #[Body('email', rules: 'required|email', description: 'Login email')]
 *   #[Body('age', type: 'integer', required: false)]
 *   public function store(Request $request): Response { ... }
 *
 * @package Siro\Core\Attributes
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Body
{
    public function __construct(
        public readonly string $key,
        public readonly string $rules = '',
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = true,
    ) {
    }
}
