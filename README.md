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
| Room page | 710 | 414 | 1,696 | 42,481 | 1,126 | 52,512 | 105,909 | 137,505 |
| Messages page | 1,113 | 454 | 1,890 | 74,779 | 1,407 | 54,100 | 103,301 | 142,669 |
| Sidebar | 1,901 | 576 | 3,364 | 94,460 | 3,621 | 58,714 | 120,930 | 151,001 |
| Search | 1,332 | 549 | 2,615 | 83,493 | 2,127 | 60,444 | 121,502 | 148,766 |
| Post a message | 226 | 113 | 567 | 2,121 | 1,392 | 9,000 | 8,004 | 7,486 |

[Shared verification](https://github.com/basecamp/once-campfire-verification) · [Detailed results](https://github.com/basecamp/once-campfire-verification/blob/main/docs/performance-review.md).

## Known differences

- Authenticated room, message-list, sidebar and search HTML bodies share a bounded
  cache with message and boost fragments: 64 MiB per persistent Octane worker,
  disabled with `CAMPFIRE_RESPONSE_CACHE_MB=0`.
  Authorization and cookies stay fresh. Identity and finished gzip bodies are cached;
  SQLite commits from any writer invalidate them. JSON and conditional requests retain their native paths.

- Browser writes use `Sec-Fetch-Site` instead of CSRF tokens. Only GET and HEAD bypass
  the check. Unsafe requests accept `same-origin` or `same-site` after any supplied
  Origin matches the effective request origin; null, foreign and invalid values return 422.
  Missing metadata is accepted only over plain HTTP without `FORCE_SSL=true`.
  HTTPS behind a proxy requires `TRUSTED_PROXIES` to name the trusted proxy addresses.
  Pages omit token fields and meta tags; existing token-bearing tabs and encrypted
  Rails cookies remain usable. Authenticated bot message routes and signed disk-upload
  capabilities retain their exemptions. The port-owned uploader requires no token tag.

- The direct-room list and New Ping picker share a nested Turbo frame, keeping the
  surrounding sidebar attached while editing. Background refreshes preserve an open
  New Ping form and selected recipients.

- Sidebar connection refresh waits for the current Turbo frame to finish loading,
  preventing an aborted response on startup or reconnect. Obsolete connections and removed frames do not reload.

- Search selects the newest 100 matching messages by insertion ID, then displays them in ID order. Backdated messages can appear in a different order from the original Rails app.

- Native writers queue on a `.lock` file beside the canonical SQLite database, up to its
  configured busy timeout. Other SQLite writers use SQLite's busy handler.

- Unchanged Rails session payloads reuse their encrypted cookie. Login, logout and
  return-to changes reissue it.

- Banned addresses are refused only on unsafe requests, as in Rails, but with 403 rather than Rails' 429.

Laravel transient request sessions and queued jobs use native storage separate from Rails' tables. Native media variants have a separate cache while retaining original blobs and signed URLs. Sidebar updates replace the member's sidebar frame rather than individual rows. The direct-room picker explicitly requests JSON, repairing an inherited browser fetch option. Legacy Marshal serialization is unsupported; JSON Rails cookies, signed identifiers, SGIDs and variations are supported. Do not replace an existing installation until the remaining ledger checks are verified.
