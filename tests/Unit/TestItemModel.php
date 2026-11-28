<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

class TestItemModel extends Model
{
    protected string $table = 'test_items';
    protected array $fillable = ['quantity'];
    protected array $casts = [
        'quantity' => 'integer',
    ];
}
