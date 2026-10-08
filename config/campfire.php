<?php

return ['secret' => env('SECRET_KEY_BASE'), 'files' => env('STORAGE_PATH', storage_path('files')), 'events' => storage_path('events.log'), 'force_ssl' => env('FORCE_SSL', false), 'response_cache_mb' => env('CAMPFIRE_RESPONSE_CACHE_MB', 64)];
