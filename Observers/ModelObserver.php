<?php

declare(strict_types=1);

namespace Siro\Core\Observers;

use Siro\Core\Model;

abstract class ModelObserver
{
    public function creating(Model $model): void
    {
    }

    public function created(Model $model): void
    {
    }

    public function updating(Model $model): void
    {
    }

    public function updated(Model $model): void
    {
    }

    public function saving(Model $model): void
    {
    }

    public function saved(Model $model): void
    {
    }

    public function deleting(Model $model): void
    {
    }

    public function deleted(Model $model): void
    {
    }

    public static function observe(string $modelClass, self $observer): void
    {
        $modelClass::registerObserver($observer);
    }
}