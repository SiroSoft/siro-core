<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class FlagModel extends Model
{
    protected string $table = 'flags';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'price', 'active'];

    /** @var array<string, string> */
    protected array $casts = ['active' => 'bool'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        $prop = $ref->getProperty('identityMap');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
