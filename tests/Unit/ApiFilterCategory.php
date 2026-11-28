<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ApiFilterCategory extends Model
{
    protected string $table = 'api_filter_categories';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'name'];
}
