<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

final class MQOwner extends Model
{
    use SoftDeletes;

    protected string $table = 'mq_owners';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function pets(): \Siro\Core\DB\Relations\HasMany
    {
        return $this->hasMany(MQPet::class, 'owner_id');
    }
}
