# Campfire in Laravel

ONCE Campfire implemented natively with Laravel 13 and PHP 8.4: existing SQLite schema and uploads, Rails-compatible login/form cookies, and the original interactive frontend. The immutable public Rails reference is pinned at `659f957`.

```sh
git submodule update --init
docker build -t once-campfire-laravel .
docker run --rm -p 8080:80 -e SECRET_KEY_BASE="$(openssl rand -hex 64)" -v campfire:/rails/storage once-campfire-laravel
```

Existing installs must reuse their `SECRET_KEY_BASE`, preserve `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` for existing push subscriptions, and mount existing storage at `/rails/storage`. The image runs FrankenPHP and Laravel Octane, with gzip, an asynchronous SQLite-backed queue worker and native Action Cable. `PHP_WORKERS` defaults to twice the CPU count; `FRANKENPHP_MODE=classic` boots Laravel for each request. `HTTP_PORT` changes the listening port.

Run the PHPUnit suite with `composer test` inside the Dockerfile's `dev` stage; native media tests require libvips. Compatibility and independent verification evidence lives in `plans/contracts.json`. Verification includes 26 independent browser assertions, actual Rails cookie continuity and live WebSocket privacy checks. Remaining checks are listed in the ledger.

## Benchmarks

Measured with 16 concurrent clients on an AMD Ryzen AI MAX+ 395 with 32 GB RAM,
with four hardware threads allocated to each app.

| HTTP workload (requests/sec) | Rails | [Django](https://github.com/basecamp/once-campfire-django) | [Laravel](https://github.com/basecamp/once-campfire-laravel) | [Express](https://github.com/basecamp/once-campfire-express) | [Elixir](https://github.com/basecamp/once-campfire-elixir) | [Go](https://github.com/basecamp/once-campfire-go) | [Rust](https://github.com/basecamp/once-campfire-rust) |
|---|---:|---:|---:|---:|---:|---:|---:|
| Room page | 236 | 62 | 764 | 2,702 | 981 | 32,132 | 35,056 |
| Messages page | 384 | 70 | 922 | 3,183 | 1,341 | 31,564 | 40,481 |
| Sidebar | 474 | 230 | 1,399 | 34,595 | 2,546 | 17,993 | 33,924 |
| Search | 415 | 120 | 1,291 | 6,725 | 1,907 | 29,775 | 34,199 |
| Post a message | 244 | 113 | 498 | 2,183 | 1,431 | 9,442 | 8,995 |

## Known differences

Laravel transient request sessions and queued jobs use native storage separate from Rails' tables. Native media variants have a separate cache while retaining original blobs and signed URLs. Sidebar updates replace the member's sidebar frame rather than individual rows. The direct-room picker explicitly requests JSON, repairing an inherited browser fetch option. Legacy Marshal serialization is unsupported; JSON Rails cookies, signed identifiers, SGIDs and variations are supported. Do not replace an existing installation until the remaining ledger checks are verified.
