<?php

namespace App\Providers;

use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\RailsCrypto;
use App\Support\ResponseCache;
use App\Support\RichTextRenderer;
use App\Support\SQLiteConnection;
use App\Support\SQLiteConnector;
use App\Support\SQLiteGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.sqlite', SQLiteConnector::class);
        Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) => new SQLiteConnection($pdo, $database, $prefix, $config));
        $this->app->scoped(RichTextRenderer::class);
        $this->app->singleton(Assets::class);
        // Holds the files written by the current request's open transaction: one per request.
        $this->app->scoped(BlobStorage::class);
        $this->app->singleton(RailsCrypto::class);
        $this->app->singleton(ResponseCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        BlobStorage::listen();
        // deploy/Caddyfile sets X-Sendfile-Type/X-Accel-Mapping on every request (overriding any
        // the client sent) and serves files under storage/files itself: response()->file() only
        // names them in X-Accel-Redirect. Without those request headers nothing changes.
        BinaryFileResponse::trustXSendfileTypeHeader();
        $connection = DB::connection();
        $connection->setQueryGrammar(new SQLiteGrammar($connection));
        if ($connection->getDriverName() === 'sqlite' && self::sqliteDatabaseReady($connection)) {
            self::applySqlitePragmas($connection->getPdo());
        }
        if (file_exists(storage_path('vapid.json'))) {
            $keys = json_decode(file_get_contents(storage_path('vapid.json')), true);
            config(['campfire.vapid_public_key' => $keys['publicKey'] ?? '']);
        }

        $this->app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;
            if ($connection->getDriverName() !== 'sqlite' || $connection->getName() === 'jobs' || ! self::sqliteDatabaseReady($connection)) {
                return;
            }
            self::applySqlitePragmas($connection->getPdo());
        });
    }

    public static function applySqlitePragmas(\PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA cache_size=2000');
        $pdo->exec('PRAGMA journal_size_limit=67108864');
        $pdo->exec('PRAGMA mmap_size=134217728');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    public static function sqliteDatabaseReady(Connection $connection): bool
    {
        $database = (string) $connection->getConfig('database');

        return $database === ':memory:' || str_contains($database, 'mode=memory') || is_file($database);
    }
}
