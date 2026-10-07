FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer

# SQLite itself, built from the official amalgamation. Debian bookworm's libsqlite3-0 (3.40.1,
# which pdo_sqlite links against) has the WAL-reset bug (https://sqlite.org/wal.html#walresetbug,
# 3.7.0 through 3.51.2): two connections writing or checkpointing at the same instant can lose
# part of a transaction during a checkpoint, and SQLite then reports "database disk image is
# malformed". PHP-FPM children, bin/cable and the queue worker each hold a connection to the same
# WAL database. The library replaces the system one for every process in the image (pdo_sqlite,
# sqlite3): it goes first on the loader path, with the same soname.
FROM php:8.4-fpm-bookworm@sha256:43e1ac38217031dbbecae60e84ccf8593722031559178d199bf56adb0145d5d0 AS sqlite
ARG SQLITE_VERSION=3530400
ARG SQLITE_YEAR=2026
ARG SQLITE_SHA3_256=454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338
RUN apt-get update && apt-get install -y --no-install-recommends build-essential curl ca-certificates && rm -rf /var/lib/apt/lists/*
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

FROM php:8.4-fpm-bookworm@sha256:43e1ac38217031dbbecae60e84ccf8593722031559178d199bf56adb0145d5d0
RUN apt-get update && apt-get install -y --no-install-recommends nginx libsqlite3-dev libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev libvips-tools ffmpeg unzip && docker-php-ext-install pdo_sqlite mbstring dom pcntl sockets opcache && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install bcmath
# SQLite from the sqlite stage, first on the loader path; the build fails unless PHP loads it.
COPY --from=sqlite /sqlite/usr/local/ /usr/local/
RUN ldconfig && \
    php -r 'exit(version_compare((new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), "3.51.3", ">=") && version_compare(SQLite3::version()["versionString"], "3.51.3", ">=") ? 0 : 1);'
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /rails
COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/db storage/files && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/php.ini /usr/local/etc/php/conf.d/campfire.ini
COPY deploy/fpm.conf /usr/local/etc/php-fpm.d/zz-campfire.conf
ENTRYPOINT ["/rails/bin/start"]
