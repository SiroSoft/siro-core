<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

final class ElWithUser extends ElUser
{
    /** @var array<int, string> */
    protected array $with = ['posts'];
}
