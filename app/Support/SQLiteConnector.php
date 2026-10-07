<?php

namespace App\Support;

use Illuminate\Database\Connectors\SQLiteConnector as BaseConnector;

/**
 * Connects through a PDO that serializes writers on a lock file beside the database.
 */
final class SQLiteConnector extends BaseConnector
{
    public function connect(array $config)
    {
        $pdo = parent::connect($config);
        if ($pdo instanceof LocksWrites) {
            $pdo->lockOn(self::lockPath($config['database'] ?? ''));
        }

        return $pdo;
    }

    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        return new WriteLockingPdo($dsn, $username, $password, $options);
    }

    private static function lockPath(mixed $database): ?string
    {
        if (! is_string($database) || $database === '' || $database === ':memory:' || str_starts_with($database, 'file:') || str_contains($database, 'mode=memory')) {
            return null;
        }

        return $database.'.lock';
    }
}
