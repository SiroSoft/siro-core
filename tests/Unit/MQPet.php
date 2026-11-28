<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

final class MQPet extends Model
{
    use SoftDeletes;

    protected string $table = 'mq_pets';

    /** @var array<int, string> */
    protected array $fillable = ['owner_id', 'name'];
}
