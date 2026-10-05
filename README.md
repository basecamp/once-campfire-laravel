# Campfire in Laravel

ONCE Campfire implemented natively with Laravel 13 and PHP 8.4: existing SQLite schema and uploads, Rails-compatible login/form cookies, and the original interactive frontend. The immutable public Rails reference is pinned at `659f957`.

```sh
git submodule update --init
docker build -t once-campfire-laravel .
docker run --rm -p 8080:80 -e SECRET_KEY_BASE="$(openssl rand -hex 64)" -v campfire:/rails/storage once-campfire-laravel
```

Existing installs must reuse their `SECRET_KEY_BASE`, preserve `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` for existing push subscriptions, and mount existing storage at `/rails/storage`. The image runs nginx with gzip, eight PHP-FPM workers, an asynchronous SQLite-backed queue worker and native Action Cable. `HTTP_PORT` changes the listening port. This stock image is the default benchmark path.

Message and boost HTML is fragment-cached (`message:{id}:{updated_at}:presentation-v3`, `boost:{id}:{updated_at}`). A hit still splices the current CSRF token into the eight quick-boost forms and does not store presentation HTML in SQLite. The sqlite connection uses WAL, `synchronous=NORMAL`, a 2000-page cache, a 64MB journal limit and a 128MB mmap, with `BEGIN IMMEDIATE`. `busy_timeout` stays 10000.

## Octane / FrankenPHP

`Dockerfile.octane` is an optional production image. It does not replace the stock Dockerfile and does not add a Caddyfile.

```sh
docker build -f Dockerfile.octane -t once-campfire-laravel-octane .
docker run --rm -p 8000:8000 -e SECRET_KEY_BASE="$(openssl rand -hex 64)" -v campfire-octane:/app/storage once-campfire-laravel-octane
```

The entrypoint installs the schema and runs `php artisan optimize`. It then starts the same background processes as `bin/start`: `php artisan queue:work --sleep=1 --tries=3 --timeout=30` and `php bin/cable`. Octane serves HTTP with `php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=$HTTP_PORT --workers=4 --max-requests=0` (`HTTP_PORT` defaults to 8000). The image installs `libvips-tools` and `ffmpeg`, the same media CLIs as the stock Dockerfile. `APP_ENV=production` and `APP_DEBUG=false`. Mount existing storage at `/app/storage`.

`bin/cable` listens on `127.0.0.1:$CABLE_PORT`. `CABLE_PORT` defaults to `HTTP_PORT + 1000`. The layout connects to `/cable`. This image keeps Octane's default FrankenPHP server and ships no Caddyfile. A proxy that can reach that loopback listener — the role `deploy/nginx.conf` plays in the stock image — must forward WebSocket `/cable` with `Upgrade`, `Connection`, `Host`, `X-Forwarded-Proto`, and `Origin`.

Run the PHPUnit suite with `composer test` inside the pinned PHP image; native media tests require libvips. Compatibility and independent verification evidence lives in `plans/contracts.json`. Verification includes 26 independent browser assertions, actual Rails cookie continuity and live WebSocket privacy checks. Remaining checks are listed in the ledger.

## Benchmarks

Measured with 16 concurrent clients on an AMD Ryzen AI MAX+ 395,
with four hardware threads allocated to each app. These published figures
predate fragment caching and the optional Octane image.

| Requests/second | Rails | Django | Laravel |
|---|---:|---:|---:|
| Room | 242 | 170 | 164 |
| Messages | 402 | 196 | 175 |
| Sidebar | 541 | 615 | 715 |
| Search | 424 | 315 | 305 |
| Post message | 225 | 154 | 137 |

At 100 WebSocket connections and five messages/second, median delivery to every
connection was 24 ms for Rails, 70 ms for Django and 42 ms for Laravel. Every message
reached every connection in both runs.

## Known differences

Laravel transient request sessions and queued jobs use native storage separate from Rails' tables. Native media variants have a separate cache while retaining original blobs and signed URLs. Sidebar updates replace the member's sidebar frame rather than individual rows. The direct-room picker explicitly requests JSON, repairing an inherited browser fetch option. Legacy Marshal serialization is unsupported; JSON Rails cookies, signed identifiers, SGIDs and variations are supported. Do not replace an existing installation until the remaining ledger checks are verified.
