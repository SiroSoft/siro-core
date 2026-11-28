<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

class ElUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function profile(): \Siro\Core\DB\Relations\HasOne
    {
        return $this->hasOne(ElProfile::class, 'user_id');
    }

    public function posts(): \Siro\Core\DB\Relations\HasMany
    {
        return $this->hasMany(ElPost::class, 'user_id');
    }

    public function comments(): \Siro\Core\DB\Relations\MorphMany
    {
        return $this->morphMany(ElComment::class, 'commentable');
    }

    public function roles(): \Siro\Core\DB\Relations\BelongsToMany
    {
        return $this->belongsToMany(ElRole::class, 'roles_users', 'user_id', 'role_id');
    }
}
