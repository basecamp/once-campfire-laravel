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
with four hardware cores allocated to each app.

| HTTP workload (requests/sec) | Rails | [Django](https://github.com/basecamp/once-campfire-django) | [Laravel](https://github.com/basecamp/once-campfire-laravel) | [Express](https://github.com/basecamp/once-campfire-express) | [Elixir](https://github.com/basecamp/once-campfire-elixir) | [Go](https://github.com/basecamp/once-campfire-go) | [Rust](https://github.com/basecamp/once-campfire-rust) | [C](https://github.com/basecamp/once-campfire-c) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page | 230 | 62 | 760 | 2,622 | 942 | 31,673 | 35,484 | 141,834 |
| Messages page | 402 | 70 | 924 | 3,245 | 1,267 | 30,746 | 40,674 | 151,564 |
| Sidebar | 468 | 229 | 1,383 | 34,938 | 2,515 | 18,586 | 34,479 | 159,850 |
| Search | 399 | 118 | 1,135 | 6,613 | 1,814 | 29,765 | 34,432 | 155,456 |
| Post a message | 248 | 112 | 498 | 2,088 | 1,400 | 9,073 | 8,998 | 7,460 |

[Shared verification](https://github.com/basecamp/once-campfire-verification) · [Detailed results](https://github.com/basecamp/once-campfire-verification/blob/main/docs/performance-review.md).

## Known differences

- The direct-room list and New Ping picker share a nested Turbo frame, keeping the
  surrounding sidebar attached while editing. Background refreshes preserve an open
  New Ping form and selected recipients.

- Sidebar connection refresh waits for the current Turbo frame to finish loading,
  preventing an aborted response on startup or reconnect. Obsolete connections and removed frames do not reload.

- Search selects the newest 100 matching messages by insertion ID, then displays them in ID order. Backdated messages can appear in a different order from the original Rails app.

- Native writers queue on a `.lock` file beside each SQLite database instead of polling SQLite's
  busy handler. Other SQLite writers still wait through the busy timeout.

- Banned addresses are refused only on unsafe requests, as in Rails, but with 403 rather than Rails' 429.

Laravel transient request sessions and queued jobs use native storage separate from Rails' tables. Native media variants have a separate cache while retaining original blobs and signed URLs. Sidebar updates replace the member's sidebar frame rather than individual rows. The direct-room picker explicitly requests JSON, repairing an inherited browser fetch option. Legacy Marshal serialization is unsupported; JSON Rails cookies, signed identifiers, SGIDs and variations are supported. Do not replace an existing installation until the remaining ledger checks are verified.
