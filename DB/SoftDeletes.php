<?php

declare(strict_types=1);

namespace Siro\Core\DB;

/**
 * Soft Deletes trait for Model.
 *
 * Overrides delete() to set deleted_at instead of removing rows,
 * and provides forceDelete() for permanent deletion.
 * Works with ModelQueryBuilder to automatically filter soft-deleted records.
 *
 * @package Siro\Core\DB
 * @phpstan-ignore trait.unused
 */
trait SoftDeletes
{
    public function delete(): bool
    {
        $table = $this->getTable();
        $timestamp = date('Y-m-d H:i:s');
        if (!\Siro\Core\Event::emit("{$table}.deleting", $this)) {
            return false;
        }
        $this->setAttribute('deleted_at', $timestamp);
        $result = $this->save();
        if ($result) {
            \Siro\Core\Event::emit("{$table}.deleted", $this);
        }
        return $result;
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $key = $this->getKeyName();
        $affected = \Siro\Core\Database::table($this->getTable())
            ->where($key, '=', $this->getAttribute($key))
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    public function restore(): bool
    {
        $this->setAttribute('deleted_at', null);
        return $this->save();
    }

    public function trashed(): bool
    {
        $deletedAt = $this->getAttribute('deleted_at');
        return $deletedAt !== null && $deletedAt !== '';
    }
}
