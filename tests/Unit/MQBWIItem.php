<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\DB\SoftDeletes;
use Siro\Core\Model;

final class MQBWIItem extends Model
{
    use SoftDeletes;

    protected string $table = 'mqbwi_items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'qty', 'price'];

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
