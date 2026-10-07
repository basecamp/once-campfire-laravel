<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * The writer lock (WriteLockingPdo) must be free whenever its connection has no transaction
 * open, however that transaction ended; otherwise every other writer blocks on it forever.
 */
final class WriteLockingPdoTest extends TestCase
{
    private string $dir;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/campfire-lock-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->database = $this->dir.'/production.sqlite3';
        touch($this->database);
        config(['database.connections.locked' => ['database' => $this->database] + config('database.connections.sqlite')]);
        // ON CONFLICT ROLLBACK: a duplicate name makes SQLite end the whole transaction itself.
        $this->connection()->statement('CREATE TABLE things (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE ON CONFLICT ROLLBACK)');
        $this->connection()->table('things')->insert(['name' => 'taken']);
    }

    protected function tearDown(): void
    {
        DB::purge('locked');
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_commit_releases_the_lock(): void
    {
        $this->connection()->transaction(function ($connection) {
            $connection->table('things')->insert(['name' => 'committed']);
            $this->assertLockHeld();
        });

        $this->assertLockFree();
        $this->assertSame(2, $this->connection()->table('things')->count());
    }

    public function test_explicit_rollback_releases_the_lock(): void
    {
        try {
            $this->connection()->transaction(function ($connection) {
                $connection->table('things')->insert(['name' => 'rolled back']);
                throw new RuntimeException('abandon');
            });
            $this->fail('The transaction should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('abandon', $exception->getMessage());
        }

        $this->assertLockFree();
        $this->assertSame(1, $this->connection()->table('things')->count());
    }

    public function test_a_statement_that_makes_sqlite_roll_back_releases_the_lock(): void
    {
        try {
            $this->connection()->transaction(function ($connection) {
                $connection->table('things')->insert(['name' => 'lost']);
                $connection->table('things')->insert(['name' => 'taken']);
            });
            $this->fail('The duplicate should have thrown.');
        } catch (QueryException) {
        }

        $this->assertFalse($this->connection()->getPdo()->inTransaction());
        $this->assertSame(0, $this->connection()->transactionLevel());
        $this->assertLockFree();
        $this->assertSame(['taken'], $this->connection()->table('things')->pluck('name')->all());
        $this->connection()->transaction(fn ($connection) => $connection->table('things')->insert(['name' => 'next']));
        $this->assertLockFree();
    }

    public function test_the_lock_is_released_as_soon_as_sqlite_ends_a_manual_transaction(): void
    {
        // Nothing calls rollBack() here: a request that lets the exception escape relies on the
        // worker's cleanup, which sees no transaction left on the PDO to roll back.
        $this->connection()->beginTransaction();
        try {
            $this->connection()->table('things')->insert(['name' => 'taken']);
            $this->fail('The duplicate should have thrown.');
        } catch (QueryException) {
        }

        $this->assertFalse($this->connection()->getPdo()->inTransaction());
        $this->assertLockFree();
    }

    public function test_a_failed_statement_that_leaves_the_transaction_open_keeps_the_lock(): void
    {
        $this->connection()->beginTransaction();
        $this->connection()->table('things')->insert(['name' => 'pending']);
        try {
            $this->connection()->table('things')->insert(['name' => null]);
            $this->fail('The NOT NULL violation should have thrown.');
        } catch (QueryException) {
        }

        $this->assertTrue($this->connection()->getPdo()->inTransaction());
        $this->assertLockHeld();
        $this->connection()->commit();
        $this->assertLockFree();
        $this->assertSame(['taken', 'pending'], $this->connection()->table('things')->pluck('name')->all());
    }

    public function test_a_failed_begin_releases_a_new_lock_without_ending_the_foreign_transaction(): void
    {
        $foreign = new PDO('sqlite:'.$this->database);
        $foreign->beginTransaction();
        $foreign->exec("INSERT INTO things (name) VALUES ('foreign')");
        $connection = $this->connection();
        $connection->getPdo()->setAttribute(PDO::ATTR_TIMEOUT, 0);
        try {
            try {
                $connection->beginTransaction();
                $this->fail('The foreign writer must prevent BEGIN.');
            } catch (PDOException) {
            }
            $this->assertFalse($connection->getPdo()->inTransaction());
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertTrue($foreign->inTransaction());
            $this->assertLockFree();
        } finally {
            $foreign->rollBack();
        }
    }

    public function test_nested_savepoint_rollback_keeps_the_outer_writer_lock(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->table('things')->insert(['name' => 'outer']);
        $connection->beginTransaction();
        $connection->table('things')->insert(['name' => 'inner']);
        $this->assertLockHeld();
        $connection->rollBack(1);
        $this->assertTrue($connection->getPdo()->inTransaction());
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertLockHeld();
        $connection->commit();
        $this->assertLockFree();
        $this->assertSame(['taken', 'outer'], $connection->table('things')->pluck('name')->all());
    }

    public function test_a_failed_commit_that_leaves_the_transaction_open_keeps_the_writer_lock(): void
    {
        $connection = $this->connection();
        $connection->statement('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $connection->statement('CREATE TABLE children (parent_id INTEGER REFERENCES parents(id) DEFERRABLE INITIALLY DEFERRED)');
        $connection->beginTransaction();
        try {
            $connection->table('children')->insert(['parent_id' => 999]);
            try {
                $connection->commit();
                $this->fail('The deferred foreign key must prevent COMMIT.');
            } catch (PDOException) {
            }
            $this->assertTrue($connection->getPdo()->inTransaction());
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertLockHeld();
        } finally {
            $connection->rollBack(0);
        }
        $this->assertLockFree();
        $this->assertSame(0, $connection->table('children')->count());
    }

    private function connection(): SQLiteConnection
    {
        return DB::connection('locked');
    }

    private function assertLockHeld(): void
    {
        $this->assertFalse($this->tryLock(), 'Another writer could take the lock while a transaction is open.');
    }

    private function assertLockFree(): void
    {
        $this->assertTrue($this->tryLock(), 'Another writer cannot take the lock.');
    }

    /** As another writer would: flock() locks of separate open files exclude each other. */
    private function tryLock(): bool
    {
        $handle = fopen($this->database.'.lock', 'c');
        try {
            return flock($handle, LOCK_EX | LOCK_NB);
        } finally {
            fclose($handle);
        }
    }
}
