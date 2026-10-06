<?php

namespace App\Support;

use PDO;
use Throwable;

/**
 * Serializes this application's SQLite writers on a file lock.
 *
 * SQLite's own busy handler polls with growing sleeps, so sixteen workers posting at once spend
 * most of their time asleep while the database sits idle. A blocking `flock` wakes the next writer
 * the moment the previous one commits. Readers never take the lock; WAL keeps them concurrent.
 */
final class WriteLockingPdo extends PDO implements LocksWrites
{
    /** @var resource|false|null */
    private $handle = null;

    private ?string $lockPath = null;

    private bool $held = false;

    public function lockOn(?string $path): void
    {
        $this->lockPath = $path;
    }

    public function beginTransaction(): bool
    {
        $acquired = $this->lock();
        try {
            return parent::beginTransaction();
        } catch (Throwable $exception) {
            if ($acquired) {
                $this->unlock();
            }
            throw $exception;
        }
    }

    public function exec(string $statement): int|false
    {
        $acquired = preg_match('/^\s*BEGIN\b/i', $statement) === 1 && $this->lock();
        try {
            $result = parent::exec($statement);
        } catch (Throwable $exception) {
            if ($acquired) {
                $this->unlock();
            }
            throw $exception;
        }
        if ($acquired && $result === false) {
            $this->unlock();
        }

        return $result;
    }

    public function commit(): bool
    {
        try {
            return parent::commit();
        } finally {
            $this->unlock();
        }
    }

    public function rollBack(): bool
    {
        try {
            return parent::rollBack();
        } finally {
            $this->unlock();
        }
    }

    /**
     * Blocks until this process may write; false when already held or no lock file is configured.
     */
    public function lock(): bool
    {
        if ($this->held || $this->lockPath === null) {
            return false;
        }
        // flock() needs no write access; fall back to reading when another user created the file.
        $this->handle ??= @fopen($this->lockPath, 'c') ?: @fopen($this->lockPath, 'r');
        if ($this->handle === false || ! flock($this->handle, LOCK_EX)) {
            return false;
        }

        return $this->held = true;
    }

    public function unlock(): void
    {
        if ($this->held && $this->handle) {
            flock($this->handle, LOCK_UN);
        }
        $this->held = false;
    }
}
