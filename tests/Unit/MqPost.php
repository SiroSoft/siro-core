<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class MqPost extends Model
{
    protected string $table = 'posts';

    /** @var array<int, string> */
    protected array $fillable = ['user_id', 'title', 'views'];

    public function user(): \Siro\Core\DB\Relations\BelongsTo
    {
        return $this->belongsTo(MqUser::class, 'user_id');
    }
}
