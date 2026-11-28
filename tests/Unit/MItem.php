<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class MItem extends Model
{
    protected string $table = 'mu_items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'price', 'active'];

    /** @var array<string, string> */
    protected array $casts = ['price' => 'float', 'active' => 'boolean'];

    /** @var array<int, string> */
    protected array $hidden = ['secret'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        foreach (['identityMap', 'lastInsertId', 'queryLog'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue(null, []);
            }
        }
    }
}
