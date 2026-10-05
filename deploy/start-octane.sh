#!/bin/sh
set -eu
: "${SECRET_KEY_BASE:?SECRET_KEY_BASE is required}"
: "${HTTP_PORT:=8000}"
: "${CABLE_PORT:=$((HTTP_PORT + 1000))}"
export CABLE_PORT
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
# Same background workers as bin/start. Cable listens on 127.0.0.1:$CABLE_PORT.
php artisan queue:work --sleep=1 --tries=3 --timeout=30 > storage/logs/queue.log 2>&1 &
queue_pid=$!
rm -f storage/cable.pid
php bin/cable start > storage/logs/cable.log 2>&1 &
cable_pid=$!
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port="$HTTP_PORT" --workers=4 --max-requests=0 &
octane_pid=$!
shutdown() {
  kill -TERM "$octane_pid" "$queue_pid" "$cable_pid" 2>/dev/null || true
  wait || true
}
trap shutdown TERM INT
wait "$octane_pid"
