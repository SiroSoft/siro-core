<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class BTMUser extends Model
{
    protected string $table = 'btm_users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function roles(): \Siro\Core\DB\Relations\BelongsToMany
    {
        return $this->belongsToMany(BTMRole::class, 'btm_role_user', 'user_id', 'role_id')->withPivot(['created_at']);
    }
}
