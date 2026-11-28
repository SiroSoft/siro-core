<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

class TestUserWithAppendsModel extends Model
{
    protected string $table = 'test_users';
    protected array $fillable = ['name'];
    protected array $appends = ['uppercase_name'];
    protected array $casts = [
        'created_at' => 'datetime',
    ];

    public function getUppercaseNameAttribute(): string
    {
        return strtoupper((string) ($this->name ?? ''));
    }
}
