<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

final class TinkerTestModel extends \Siro\Core\Model
{
    protected string $table = 'tinker_test';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'name'];
}
