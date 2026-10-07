<?php

namespace App\Providers;

use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\RailsCrypto;
use App\Support\RichTextRenderer;
use App\Support\SQLiteGrammar;
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
        $this->app->singleton(RichTextRenderer::class);
        $this->app->singleton(Assets::class);
        // Holds the files written by the current request's open transaction: one per request.
        $this->app->scoped(BlobStorage::class);
        $this->app->singleton(RailsCrypto::class);
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
        if (file_exists(storage_path('vapid.json'))) {
            $keys = json_decode(file_get_contents(storage_path('vapid.json')), true);
            config(['campfire.vapid_public_key' => $keys['publicKey'] ?? '']);
        }
    }
}
