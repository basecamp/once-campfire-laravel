<?php

namespace App\Support;

use Illuminate\Database\SQLiteConnection as BaseConnection;

/**
 * Takes the writer lock around inserts, updates and deletes that run outside a transaction, so
 * every write from this application queues on the same lock as `DB::transaction()` (see
 * WriteLockingPdo). Transactions take the lock themselves on BEGIN.
 */
final class SQLiteConnection extends BaseConnection
{
    public function insert($query, $bindings = [])
    {
        return $this->serialized(fn () => parent::insert($query, $bindings));
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->serialized(fn () => parent::affectingStatement($query, $bindings));
    }

    private function serialized(callable $write): mixed
    {
        $pdo = $this->transactions === 0 ? $this->getPdo() : null;
        if (! $pdo instanceof LocksWrites || ! $pdo->lock()) {
            return $write();
        }
        try {
            return $write();
        } finally {
            $pdo->unlock();
        }
    }
}
