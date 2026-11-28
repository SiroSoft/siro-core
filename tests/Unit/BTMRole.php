<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class BTMRole extends Model
{
    protected string $table = 'btm_roles';

    /** @var array<int, string> */
    protected array $fillable = ['name'];
}
