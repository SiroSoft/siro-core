<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;
use Siro\Core\Observers\ModelObserver;

final class MObserver extends ModelObserver
{
    public function creating(Model $model): void
    {
    }
}
