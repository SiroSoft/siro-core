<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ElPost extends Model
{
    protected string $table = 'posts';

    /** @var array<int, string> */
    protected array $fillable = ['user_id', 'title'];

    public function owner(): \Siro\Core\DB\Relations\BelongsTo
    {
        return $this->belongsTo(ElUser::class, 'user_id');
    }
}
