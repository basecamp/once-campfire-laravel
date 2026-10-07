<?php

namespace App\Support;

use PDO;
use PDOException;

/**
 * Serializes this application's SQLite writers on a file lock.
 *
 * Native writers share a bounded wait on the canonical database's lock file rather than
 * SQLite's growing busy-handler sleeps. Readers never take the lock; WAL keeps them concurrent.
 */
final class WriteLockingPdo extends PDO implements LocksWrites
{
    /** @var resource|false|null */
    private $handle = null;

    private ?string $lockPath = null;

    private int $timeoutMilliseconds = 10000;

    private bool $held = false;

    /** Taken by BEGIN: held until SQLite reports the transaction over, not just until a statement ends. */
    private bool $heldForTransaction = false;

    public function lockOn(?string $path, int $timeoutMilliseconds = 10000): void
    {
        $this->lockPath = $path;
        $this->timeoutMilliseconds = max(0, $timeoutMilliseconds);
    }

    public function beginTransaction(): bool
    {
        $this->lockForTransaction();
        try {
            return parent::beginTransaction();
        } finally {
            $this->releaseIfTransactionEnded();
        }
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*BEGIN\b/i', $statement) === 1) {
            $this->lockForTransaction();
        }
        try {
            return parent::exec($statement);
        } finally {
            $this->releaseIfTransactionEnded();
        }
    }

    public function commit(): bool
    {
        try {
            return parent::commit();
        } finally {
            $this->releaseIfTransactionEnded();
        }
    }

    public function rollBack(): bool
    {
        try {
            return parent::rollBack();
        } finally {
            $this->releaseIfTransactionEnded();
        }
    }

    /**
     * Waits up to SQLite's busy timeout; false when held or no lock file is configured.
     */
    public function lock(): bool
    {
        if ($this->held || $this->lockPath === null) {
            return false;
        }
        // flock() needs no write access; fall back to reading when another user created the file.
        $this->handle ??= @fopen($this->lockPath, 'c') ?: @fopen($this->lockPath, 'r');
        if ($this->handle === false) {
            return false;
        }
        $deadline = hrtime(true) + $this->timeoutMilliseconds * 1000000;
        while (! flock($this->handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (! $wouldBlock) {
                return false;
            }
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                $exception = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked (writer lock timeout)', 5);
                $exception->errorInfo = ['HY000', 5, 'database is locked (writer lock timeout)'];
                throw $exception;
            }
            usleep(min(1000, max(1, intdiv($remaining, 1000))));
        }

        return $this->held = true;
    }

    public function unlock(): void
    {
        if ($this->held && parent::inTransaction()) {
            $this->heldForTransaction = true;

            return;
        }
        if ($this->held && $this->handle) {
            flock($this->handle, LOCK_UN);
        }
        $this->held = false;
        $this->heldForTransaction = false;
    }

    /**
     * SQLite ends a transaction itself when a statement fails under ON CONFLICT ROLLBACK,
     * RAISE(ROLLBACK), SQLITE_FULL and the like. Laravel then never calls rollBack(): it only
     * does while PDO still reports a transaction.
     */
    public function releaseIfTransactionEnded(): void
    {
        if ($this->heldForTransaction && ! parent::inTransaction()) {
            $this->unlock();
        }
    }

    private function lockForTransaction(): void
    {
        if ($this->lock()) {
            $this->heldForTransaction = true;
        }
    }
}
