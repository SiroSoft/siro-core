<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class AuthUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'email', 'role', 'status', 'token_version'];
}
