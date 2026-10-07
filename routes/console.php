<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use Symfony\Component\Process\Process;

Artisan::command('campfire:install', function () {
    $path = config('database.connections.sqlite.database');
    if (! file_exists($path)) {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }touch($path);
    }
    $fresh = ! DB::getSchemaBuilder()->hasTable('users');
    DB::connection()->getPdo()->exec(file_get_contents(database_path('schema.sql')));
    if ($fresh) {
        foreach (json_decode(file_get_contents(database_path('versions.json')), true) as $version) {
            DB::table('schema_migrations')->insert(['version' => $version]);
        }
        DB::table('ar_internal_metadata')->insert(['key' => 'environment', 'value' => app()->environment(), 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::statement('CREATE INDEX IF NOT EXISTS index_messages_on_room_id_and_updated_at ON messages(room_id,updated_at)');
    DB::statement('PRAGMA journal_mode=WAL');
    DB::statement('PRAGMA busy_timeout=10000');
    $jobs = storage_path('jobs.sqlite3');
    if (! file_exists($jobs)) {
        touch($jobs);
    }
    DB::connection('jobs')->statement('PRAGMA journal_mode=WAL');
    DB::connection('jobs')->getPdo()->exec('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,queue VARCHAR NOT NULL,payload TEXT NOT NULL,attempts INTEGER NOT NULL,reserved_at INTEGER,available_at INTEGER NOT NULL,created_at INTEGER NOT NULL); CREATE INDEX IF NOT EXISTS jobs_queue_index ON jobs(queue); CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,uuid VARCHAR UNIQUE NOT NULL,connection TEXT NOT NULL,queue TEXT NOT NULL,payload TEXT NOT NULL,exception TEXT NOT NULL,failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);');
    if (! file_exists(config('campfire.events'))) {
        touch(config('campfire.events'));
    }
    if (! file_exists(storage_path('vapid.json'))) {
        $keys = getenv('VAPID_PUBLIC_KEY') && getenv('VAPID_PRIVATE_KEY') ? ['publicKey' => getenv('VAPID_PUBLIC_KEY'), 'privateKey' => getenv('VAPID_PRIVATE_KEY')] : VAPID::createVapidKeys();
        file_put_contents(storage_path('vapid.json'), json_encode($keys));
        chmod(storage_path('vapid.json'), 0600);
    }
    $this->info('Campfire schema ready');
});
Artisan::command('campfire:backup {destination}', function () {
    $dest = $this->argument('destination');
    if (file_exists($dest)) {
        throw new RuntimeException('Destination already exists');
    }mkdir($dest, 0700, true);
    DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($dest.'/production.sqlite3'));
    $files = new Process(['cp', '-a', config('campfire.files'), $dest.'/files']);
    $files->mustRun();
    DB::connection('jobs')->getPdo()->exec('VACUUM INTO '.DB::connection('jobs')->getPdo()->quote($dest.'/jobs.sqlite3'));
    copy(storage_path('vapid.json'), $dest.'/vapid.json');
    chmod($dest.'/vapid.json', 0600);
    $this->info('Database, queued jobs, uploaded files and push keys backed up');
});
