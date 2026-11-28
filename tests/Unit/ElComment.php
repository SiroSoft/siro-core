<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class ElComment extends Model
{
    protected string $table = 'comments';

    /** @var array<int, string> */
    protected array $fillable = ['commentable_type', 'commentable_id', 'body'];

    public function commentable(): \Siro\Core\DB\Relations\MorphTo
    {
        return $this->morphTo('commentable');
    }
}
