# mishka-php — self-contained runtime image (v0.7.2+).
#
# Carries app code + vendor + PHP + extensions. Bind-mount removed from
# docker-compose.yml; the image IS the deploy artifact. See DOCS.md
# decision #64 for the v0.6.x→v0.7.2 deploy-model shift (was: thin PHP
# base + bind-mount source from host; now: COPY app + composer install
# inside the image, pull-and-run on any docker host).
#
# Build with (CI does this on Nalle via the build job in ci.yml):
#   docker build -t 192.168.4.9:5000/mishka-php:sha-$(git rev-parse --short HEAD) .
#
# Used by both mishka-app (php -S) and mishka-worker (karhu push:worker).
# Same image; different `command:` overrides per service in compose.

FROM php:8.4-cli

# System libs needed for PHP extensions.
#   libgmp-dev      → gmp (Web Push crypto fast path)
#   libpq-dev       → pdo_pgsql (production database driver)
#   libsqlite3-dev  → pdo_sqlite (test harness — kept so the image can
#                                 self-test offline if ever needed)
# -dev packages stay in the image for now; v0.7.3 multi-stage candidate
# would split a "builder" stage from a "runtime" stage to shave 100-200MB.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgmp-dev \
        libpq-dev \
        libsqlite3-dev \
        unzip \
        git \
    && docker-php-ext-install \
        gmp \
        pdo_pgsql \
        pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
# unzip + git are needed by composer to install --prefer-dist packages
# (unzip extracts ZIP archives from packagist; git clones any source-only
# fallback packages). Without these, `composer install` fails with the
# "zip extension and unzip/7z commands are both missing" error.

# Composer binary for ops-side commands inside the running container
# (e.g. `docker exec mishka-app composer outdated`). The binary itself
# doesn't ship PHP — multi-stage COPY from the official composer image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Layer-cache discipline: composer.{json,lock} BEFORE the rest of the
# source so the vendor layer only rebuilds when the lockfile changes.
# --no-scripts: defensive (mishka has no composer lifecycle hooks today,
# but if a future dep adds one needing the full source tree, it'd fail
# here without --no-scripts).
# --no-autoloader: skips vendor/autoload.php + vendor/composer/installed.php
# at this stage because app/ isn't COPYed yet. The subsequent
# `composer dump-autoload` regenerates them all once the full tree is in.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    --no-autoloader

# Now COPY the rest of the app source (minus .dockerignore exclusions).
# composer.{json,lock} get re-COPYed here too but with identical content
# (build context didn't change between the two COPYs) — no-op layer churn.
COPY . .

# Regenerate autoload with the full source tree present.
# --classmap-authoritative: the image is immutable in prod, so we don't
# need filesystem fallback lookups. Faster autoload, smaller boot cost.
# This step ALSO regenerates vendor/composer/installed.php so
# Composer\InstalledVersions::getVersion() works at runtime (the build
# job's smoke step verifies this against twig/twig).
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Touch an empty /app/.env so bootstrap.php's Dotenv::safeLoad() doesn't
# emit an E_WARNING (which the karhu ExceptionHandler immediately converts
# to ErrorException → 500 on every request). Container env vars come via
# compose's `env_file: [.env]` → process env → $_ENV; the empty file's
# contents are ignored because Dotenv::createImmutable preserves pre-set
# $_ENV entries. Mirrors the v0.7.1 BootstrapSmokeTest fixture pattern
# (DOCS #58 postmortem) — the real fundamental fix would be a file_exists
# guard in public/bootstrap.php, flagged for v0.7.3+.
RUN touch /app/.env

EXPOSE 8080

# mishka-app default: serve via PHP's built-in dev server on :8080.
# mishka-worker overrides this in docker-compose.yml with the karhu CLI
# (php vendor/bjornbasar/karhu/bin/karhu push:worker).
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
