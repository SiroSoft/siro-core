<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;
use Siro\Core\DB\Relations\HasMany;

final class HMAuthor extends Model
{
    protected string $table = 'authors';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function books(): HasMany
    {
        return $this->hasMany(HMBook::class, 'author_id');
    }
}
