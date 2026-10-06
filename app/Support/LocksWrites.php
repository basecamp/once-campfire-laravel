<?php

namespace App\Support;

/**
 * A PDO that serializes this application's SQLite writers on a lock file (see WriteLockingPdo).
 */
interface LocksWrites
{
    public function lockOn(?string $path): void;

    /** Blocks until this process may write; false when already held or no lock file is configured. */
    public function lock(): bool;

    public function unlock(): void;
}
