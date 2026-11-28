<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

final class HMBook extends Model
{
    protected string $table = 'books';

    /** @var array<int, string> */
    protected array $fillable = ['author_id', 'title'];
}
