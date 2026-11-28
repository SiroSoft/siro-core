<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Security;

use Siro\Core\Model;

/** @extends Model<Pen150Note> */

final class Pen150Note extends Model
{
    protected string $table = 'pen150_notes';

    /** @var array<int, string> */
    protected array $fillable = ['title', 'user_id'];
}
