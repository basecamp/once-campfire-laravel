<?php

namespace App\Support;

use Closure;
use Illuminate\Database\SQLiteConnection as BaseConnection;

/**
 * Takes the writer lock around inserts, updates and deletes that run outside a transaction, so
 * every write from this application queues on the same lock as `DB::transaction()` (see
 * WriteLockingPdo). Transactions take the lock themselves on BEGIN, and give it back as soon as
 * a statement leaves SQLite without one.
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

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } finally {
            $pdo = $this->getRawPdo();
            if ($pdo instanceof LocksWrites) {
                $pdo->releaseIfTransactionEnded();
            }
        }
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
