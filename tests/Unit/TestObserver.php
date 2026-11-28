<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Observers\ModelObserver;

final class TestObserver extends ModelObserver
{
    public function restored(\Siro\Core\Model $model): void
    {
    }
}
