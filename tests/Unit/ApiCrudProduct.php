<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\Model;

final class ApiCrudProduct extends Model
{
    protected string $table = 'api_crud_products';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'name', 'price', 'status', 'category_id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ApiCrudCategory::class, 'category_id');
    }
}
