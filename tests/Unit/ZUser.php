<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ZUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'email', 'password'];

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
