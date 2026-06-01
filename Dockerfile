# mishka-php — runtime image for mishka-app + mishka-worker.
#
# Both containers bind-mount /data/personal/mishka over /app at runtime, so
# this image is intentionally minimal: a PHP CLI + the three extensions
# mishka actually uses + composer for ops-side `composer install` / `audit`.
#
# Build with:
#   docker build -t mishka-php:test /data/personal/mishka
#
# History — previously this image was built ad-hoc with `docker-php-ext-install
# pdo_pgsql pdo_sqlite` and no Dockerfile. v0.6.0 added minishlink/web-push,
# which triggers an E_USER_NOTICE complaining about a missing GMP/BCMath
# extension; karhu's ExceptionHandler converts notices to ErrorException, so
# without GMP the live site 500s on any /me/notifications render. This
# Dockerfile bakes GMP in so a container restart doesn't lose it (the live
# fix via `docker exec docker-php-ext-install gmp` was a stopgap).

FROM php:8.4-cli

# System libs needed to build the bundled extensions.
#   libgmp-dev      → gmp (Web Push crypto fast path)
#   libpq-dev       → pdo_pgsql (production database driver)
#   libsqlite3-dev  → pdo_sqlite (test harness)
# php-ext-install handles the actual compile + load.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgmp-dev \
        libpq-dev \
        libsqlite3-dev \
    && docker-php-ext-install \
        gmp \
        pdo_pgsql \
        pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer for ops-side `composer install` / `composer audit` / dependency
# updates. Multi-stage copy from the official image — composer doesn't ship
# its own PHP, so the binary is portable.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# mishka-app default: serve via PHP's built-in dev server on :8080.
# mishka-worker overrides this in docker-compose.yml with the karhu CLI
# (php vendor/bjornbasar/karhu/bin/karhu push:worker).
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
