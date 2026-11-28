<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class RelUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function profile(): \Siro\Core\DB\Relations\HasOne
    {
        return $this->hasOne(RelProfile::class, 'user_id');
    }

    public function comments(): \Siro\Core\DB\Relations\MorphMany
    {
        return $this->morphMany(RelComment::class, 'commentable');
    }

    public function roles(): \Siro\Core\DB\Relations\BelongsToMany
    {
        return $this->belongsToMany(RelRole::class, 'roles_users', 'user_id', 'role_id');
    }
}
