<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class RelProfile extends Model
{
    protected string $table = 'profiles';

    /** @var array<int, string> */
    protected array $fillable = ['user_id', 'bio'];
}
