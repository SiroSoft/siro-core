<?php

declare(strict_types=1);

namespace Siro\Core\Attributes;

use Attribute;

/**
 * Attach middleware to a controller class (all methods) or a single method.
 *
 * Method-level entries run after class-level ones. Accepts aliases with
 * params exactly like route definitions ('throttle:60,1', 'auth:admin').
 *
 * Usage:
 *   #[Middleware('auth')]
 *   final class ProductController extends Controller
 *   {
 *       #[Middleware('throttle:10,1')]
 *       public function store(Request $request): Response { ... }
 *   }
 *
 * @package Siro\Core\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @var array<int, callable|string> */
    public readonly array $middleware;

    /** @param array<int, callable|string>|callable|string $middleware */
    public function __construct(callable|string|array ...$middleware)
    {
        $flattened = [];
        foreach ($middleware as $entry) {
            $items = is_array($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                if (!is_string($item) && !is_callable($item)) {
                    throw new \InvalidArgumentException('Middleware entries must be class-strings, aliases, or callables.');
                }
                $flattened[] = $item;
            }
        }
        $this->middleware = $flattened;
    }
}
