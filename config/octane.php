<?php

use App\Octane\FlushSharedViewData;
use App\Octane\RollBackOpenTransactions;
use App\Support\Assets;
use App\Support\RailsCrypto;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/*
 * Laravel Octane under FrankenPHP worker mode (deploy/Caddyfile, public/frankenphp-worker.php).
 * One application is booted per worker thread and serves MAX_REQUESTS requests (bin/start) from a
 * per-request clone ("sandbox"); the listeners below reset what would otherwise survive between
 * requests. See README.md, "Worker mode".
 */
return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            // The view factory is warmed and shared by every request; AuthenticateCampfire shares
            // `currentUser` (and ShareErrorsFromSession `errors`) into it.
            FlushSharedViewData::class,
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // Uploaded temp files: PHP deletes them only at the end of a script, which a worker
            // never reaches.
            FlushUploadedFiles::class,
            // And drop the request's shared view data (the user model) as soon as it is done.
            FlushSharedViewData::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // The SQLite connections persist across requests: never let one request's unfinished
            // transaction (and its write lock) leak into the next.
            RollBackOpenTransactions::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
     * Resolved once per worker and shared by every request. These hold no request state:
     * RailsCrypto caches PBKDF2-derived keys (re-derived if the secret changes), Assets the
     * digest manifest. RichTextRenderer is scoped (it memoizes mention and blob lookups per
     * request) and keeps its two HTMLPurifier instances in statics instead.
     */
    'warm' => [
        ...Octane::defaultServicesToWarm(),
        RailsCrypto::class,
        Assets::class,
    ],

    'flush' => [
        //
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    'garbage' => 50,

    'max_execution_time' => 30,

    'state_file' => env('OCTANE_STATE_FILE', storage_path('logs/octane-server-state.json')),

];
