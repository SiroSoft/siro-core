<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class BMUUser extends Model
{
    protected string $table = 'bmu_users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function roles(): \Siro\Core\DB\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            BMURole::class,
            'bmu_role_user',
            'user_id',
            'role_id'
        )->withPivot(['created_at', 'extra']);
    }
}
