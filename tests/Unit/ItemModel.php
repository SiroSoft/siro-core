<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ItemModel extends Model
{
    protected string $table = 'items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'price', 'active'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        $prop = $ref->getProperty('identityMap');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
