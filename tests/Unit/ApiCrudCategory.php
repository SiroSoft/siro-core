<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ApiCrudCategory extends Model
{
    protected string $table = 'api_crud_categories';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'name'];
}
