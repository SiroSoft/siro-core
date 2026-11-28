<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

final class MSUser extends Model
{
    use SoftDeletes;

    protected string $table = 'ms_users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

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
