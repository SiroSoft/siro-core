<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class MqUser extends Model
{
    use \Siro\Core\DB\SoftDeletes;

    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'email'];


    public function posts(): \Siro\Core\DB\Relations\HasMany
    {
        return $this->hasMany(MqPost::class, 'user_id');
    }
}
