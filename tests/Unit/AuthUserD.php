<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class AuthUserD extends Model
{
    protected string $table = 'amw_users';
    /** @var array<int, string> */
    protected array $fillable = ['id', 'name', 'email', 'role', 'status', 'token_version', 'created_at', 'avatar'];

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
