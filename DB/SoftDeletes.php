<?php

declare(strict_types=1);

namespace Siro\Core\DB;

trait SoftDeletes
{
    public function delete(): bool
    {
        $this->setAttribute('deleted_at', date('Y-m-d H:i:s'));
        return $this->save();
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
}
