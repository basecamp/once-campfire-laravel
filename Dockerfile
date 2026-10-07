# syntax = docker/dockerfile:1
#
# FrankenPHP (Caddy + PHP 8.4 ZTS) serving the app through Laravel Octane's worker, plus the
# Action Cable server (bin/cable) and the queue worker; see bin/start and deploy/Caddyfile.
#
#   docker build -t once-campfire-laravel .
#   docker build --target dev -t once-campfire-laravel:dev .     # dev dependencies, for composer test
#
# Stages: sqlite (SQLite from source) -> base (FrankenPHP, extensions, libvips, ffmpeg)
# -> deps (production vendor/) -> prod (default); dev = deps + dev dependencies.

# FrankenPHP 1.13 / PHP 8.4 ZTS / Caddy 2.11 on Debian trixie (multi-arch index: linux/amd64, linux/arm64, ...).
ARG FRANKENPHP_IMAGE=docker.io/dunglas/frankenphp:1-php8.4-trixie@sha256:81f7030a2b7230f26dbdf281bee328e03221a33b3545a5d432d7f67e3346d704
ARG COMPOSER_IMAGE=docker.io/library/composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c

FROM ${COMPOSER_IMAGE} AS composer


# SQLite itself, built from the official amalgamation. Debian trixie's libsqlite3-0 (3.46.1, which
# pdo_sqlite links against) has the WAL-reset bug (https://sqlite.org/wal.html#walresetbug,
# 3.7.0 through 3.51.2): two connections writing or checkpointing at the same instant can lose
# part of a transaction during a checkpoint, and SQLite then reports "database disk image is
# malformed". FrankenPHP's worker threads (one connection each, in one process), bin/cable and
# the queue worker are exactly that pattern. Rails' sqlite3 gem 2.9.6 bundles 3.53.2.
# The library replaces the system one for every process in the image (pdo_sqlite, sqlite3):
# it goes first on the loader path, with the same soname.
FROM ${FRANKENPHP_IMAGE} AS sqlite
ARG SQLITE_VERSION=3530400
ARG SQLITE_YEAR=2026
ARG SQLITE_SHA3_256=454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338
# Debian's compile options (`PRAGMA compile_options` of libsqlite3-0), so nothing else changes.
RUN set -eux; \
    cd /tmp; \
    curl -fsSLo sqlite.tar.gz "https://www.sqlite.org/${SQLITE_YEAR}/sqlite-autoconf-${SQLITE_VERSION}.tar.gz"; \
    echo "SHA3-256(sqlite.tar.gz)= ${SQLITE_SHA3_256}" > sqlite.sha3; \
    [ "$(openssl dgst -sha3-256 sqlite.tar.gz)" = "$(cat sqlite.sha3)" ]; \
    tar xzf sqlite.tar.gz; \
    cd "sqlite-autoconf-${SQLITE_VERSION}"; \
    CFLAGS="-O2 -g0 -DSQLITE_ENABLE_COLUMN_METADATA -DSQLITE_ENABLE_FTS3_PARENTHESIS \
      -DSQLITE_ENABLE_FTS3_TOKENIZER -DSQLITE_ENABLE_PREUPDATE_HOOK -DSQLITE_ENABLE_STMTVTAB \
      -DSQLITE_ENABLE_UNLOCK_NOTIFY -DSQLITE_LIKE_DOESNT_MATCH_BLOBS -DSQLITE_MAX_SCHEMA_RETRY=25 \
      -DSQLITE_MAX_VARIABLE_NUMBER=250000 -DSQLITE_SECURE_DELETE -DSQLITE_SOUNDEX -DSQLITE_USE_URI \
      -DSQLITE_DEFAULT_SECTOR_SIZE=4096" \
      ./configure --prefix=/usr/local --libdir="/usr/local/lib/$(gcc -dumpmachine)" --soname=legacy \
        --disable-static --disable-readline --fts3 --fts4 --fts5 --rtree --session --dbpage --dbstat; \
    make -j"$(nproc)"; \
    make install DESTDIR=/sqlite; \
    rm -rf /sqlite/usr/local/share /sqlite/usr/local/lib/*/pkgconfig


FROM ${FRANKENPHP_IMAGE} AS base

# libvips-tools (vips CLI): image variants; ffmpeg/ffprobe: video previews and analysis.
RUN apt-get update -qq && \
    apt-get install --no-install-recommends -y libvips-tools ffmpeg && \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*

# SQLite (see the sqlite stage) and its CLI. /usr/local/lib/<multiarch> comes first in
# /etc/ld.so.conf.d/<multiarch>.conf, ahead of Debian's copy; the check fails the build unless
# PHP really loads it.
COPY --from=sqlite /sqlite/usr/local/ /usr/local/
RUN ldconfig && \
    php -r 'exit(version_compare((new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), "3.51.3", ">=") && version_compare(SQLite3::version()["versionString"], "3.51.3", ">=") ? 0 : 1);' && \
    sqlite3 --version

# bcmath (Web Push), pcntl and sockets (Workerman, queue:work), as in the PHP-FPM image, plus
# gmp, which minishlink/web-push recommends for its elliptic-curve math, and event, which
# Workerman uses instead of select() (limited to FD_SETSIZE, 1024 descriptors: about a thousand
# WebSocket connections). pdo_sqlite, mbstring, dom, opcache and posix ship with the base image.
RUN install-php-extensions bcmath event gmp pcntl sockets

# The base image gives frankenphp cap_net_bind_service as a file capability; under
# --cap-drop=ALL that makes exec fail. Without it, root still binds :80, and other users bind
# high ports (Docker's own network namespaces also allow low ports to everyone).
RUN cp /usr/local/bin/frankenphp /tmp/frankenphp && mv /tmp/frankenphp /usr/local/bin/frankenphp && \
    ! getcap /usr/local/bin/frankenphp | grep -q cap_

COPY deploy/php.ini $PHP_INI_DIR/conf.d/zz-campfire.ini

WORKDIR /rails

ENV XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer /usr/bin/composer /usr/local/bin/composer


# Production dependencies, cached on composer.lock alone.
FROM base AS deps
RUN apt-get update -qq && \
    apt-get install --no-install-recommends -y unzip git && \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*
COPY composer.json composer.lock ./
RUN --mount=type=cache,id=campfire-laravel-composer,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist


# composer test: dev dependencies. .dockerignore keeps tests/ and compat/ out of the build
# context, so they are mounted at run time (README).
FROM deps AS dev
RUN --mount=type=cache,id=campfire-laravel-composer,target=/tmp/composer-cache \
    composer install --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist
COPY . .
RUN composer dump-autoload --no-interaction && \
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && \
    chmod -R a+rwX storage bootstrap/cache


FROM deps AS prod
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction && \
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/db storage/files && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R a+rwX storage bootstrap/cache
EXPOSE 80
# The base image's healthcheck polls Caddy's admin API, which is off.
HEALTHCHECK NONE
ENTRYPOINT ["/rails/bin/start"]
