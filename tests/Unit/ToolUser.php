<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ToolUser extends Model
{
    protected string $table = 'tool_users';
    protected array $fillable = ['name', 'email', 'role'];
}
