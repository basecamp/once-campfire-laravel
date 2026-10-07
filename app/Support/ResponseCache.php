<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

// Page and fragment cache owned by one persistent Octane worker. A separate SQLite connection
// observes every committed write, including this worker's and other implementations'.
final class ResponseCache
{
    private ?PDO $monitor = null;

    private ?string $database = null;

    private ?int $version = null;

    private array $entries = [];

    private int $bytes = 0;

    public function epoch(): ?int
    {
        if ($this->limit() === 0) {
            $this->clear();

            return null;
        }
        // Uncommitted presentations must never enter the committed generation.
        if (DB::connection()->transactionLevel() > 0) {
            return null;
        }
        try {
            $database = DB::connection()->getDatabaseName();
            if ($database === ':memory:' || ! is_file($database)) {
                $this->clear();

                return null;
            }
            if ($this->database !== $database) {
                $this->monitor = new PDO('sqlite:'.$database);
                $this->monitor->exec('PRAGMA query_only=ON');
                $this->database = $database;
                $this->version = null;
                $this->clear();
            }
            $version = (int) $this->monitor->query('PRAGMA data_version')->fetchColumn();
            if ($this->version !== $version) {
                $this->clear();
                $this->version = $version;
            }

            return $version;
        } catch (PDOException) {
            $this->clear();
            $this->version = null;

            return null;
        }
    }

    public function get(string $key, int $epoch): ?array
    {
        return $this->many([$key], $epoch)[$key];
    }

    public function many(array $keys, int $epoch): array
    {
        if ($this->epoch() !== $epoch) {
            return array_fill_keys($keys, null);
        }

        return array_combine($keys, array_map($this->entry(...), $keys));
    }

    private function entry(string $key): ?array
    {
        if (! isset($this->entries[$key])) {
            return null;
        }
        $entry = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;

        return $entry['value'];
    }

    public function put(string $key, int $epoch, array $value): void
    {
        if ($this->epoch() !== $epoch) {
            return;
        }
        $bytes = strlen($key) + strlen(serialize($value));
        if ($bytes > $this->limit()) {
            return;
        }
        if (isset($this->entries[$key])) {
            $this->bytes -= $this->entries[$key]['bytes'];
            unset($this->entries[$key]);
        }
        while ($this->entries && ($this->bytes + $bytes > $this->limit() || count($this->entries) >= 4096)) {
            $oldest = array_key_first($this->entries);
            $this->bytes -= $this->entries[$oldest]['bytes'];
            unset($this->entries[$oldest]);
        }
        $this->entries[$key] = ['bytes' => $bytes, 'value' => $value];
        $this->bytes += $bytes;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->bytes = 0;
    }

    private function limit(): int
    {
        return max(0, (int) config('campfire.response_cache_mb', 64)) * 1024 * 1024;
    }
}
