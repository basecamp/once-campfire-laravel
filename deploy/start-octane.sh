#!/bin/sh
set -eu
: "${SECRET_KEY_BASE:?SECRET_KEY_BASE is required}"
export APP_ENV=production APP_DEBUG=false
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_DATABASE:-/app/storage/db/production.sqlite3}"
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=database
export LOG_LEVEL="${LOG_LEVEL:-error}"
export LOG_CHANNEL="${LOG_CHANNEL:-stderr}"
export APP_KEY="${APP_KEY:-$(php -r 'echo "base64:".base64_encode(hash_hmac("sha256","laravel-framework",getenv("SECRET_KEY_BASE"),true));')}"
mkdir -p storage/db storage/files storage/framework/cache/data storage/framework/cache/HTML storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache || true
php artisan campfire:install --no-interaction
php artisan optimize
# No custom Caddyfile. Octane FrankenPHP defaults. Workers=4 for 4-CPU budget.
exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=4 --max-requests=0
