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
 */
trait SoftDeletes
{
    public function delete(): bool
    {
        $table = $this->getTable();
        $timestamp = date('Y-m-d H:i:s');
        if (\Siro\Core\Event::hasListeners("{$table}.deleting")) {
            \Siro\Core\Event::dispatch("{$table}.deleting", [$this]);
        }
        $this->setAttribute('deleted_at', $timestamp);
        $result = $this->save();
        if ($result && \Siro\Core\Event::hasListeners("{$table}.deleted")) {
            \Siro\Core\Event::dispatch("{$table}.deleted", [$this]);
        }
        return $result;
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $affected = \Siro\Core\Database::table($this->getTable())
            ->where('id', '=', $this->getAttribute('id'))
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
