<?php

namespace App\Octane;

use PDO;

/**
 * Database connections outlive requests in a worker. DB::transaction() always commits or rolls
 * back, but a request that dies between a manual beginTransaction() and its commit would leave
 * the connection inside a transaction, holding SQLite's write lock for every later request of
 * that worker. Roll any such transaction back once the request is over.
 */
final class RollBackOpenTransactions
{
    public function handle($event): void
    {
        if (! $event->sandbox->resolved('db')) {
            return;
        }
        foreach ($event->sandbox->make('db')->getConnections() as $connection) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            } elseif (($pdo = $connection->getRawPdo()) instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
