<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

// Helper classes for testing

class TestPostModel extends Model
{
    protected string $table = 'test_posts';
    protected array $fillable = ['created_at'];
    protected array $casts = [
        'created_at' => 'datetime',
    ];
}
