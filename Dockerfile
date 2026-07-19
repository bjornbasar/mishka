# mishka-php — self-contained runtime image (v0.7.6+).
#
# Multi-stage: builder compiles PHP extensions + runs composer install;
# runtime carries ONLY the final app + vendor + extension .so files +
# runtime shared libs. -dev packages, unzip, git, and composer's ZIP
# cache never reach the deployed image.
#
# Historical shape:
#   v0.6.x  — thin base + bind-mounted source from host
#   v0.7.2  — single-stage self-contained (see DOCS #64)
#   v0.7.6  — multi-stage builder/runtime split (see DOCS #68)
#
# Build with (CI does this on Ruxa via the build job in ci.yml):
#   docker build -t 192.168.4.9:5000/mishka-php:sha-$(git rev-parse --short HEAD) .
#
# Used by both mishka-app (php -S) and mishka-worker (karhu push:worker).
# Same image; different `command:` overrides per service in compose.

# ────────────────────────────────────────────────────────────────────
# Stage 1: builder — compile extensions, install composer deps
# ────────────────────────────────────────────────────────────────────

FROM php:8.4-cli AS builder

# System libs + build tools for compiling PHP extensions and running
# `composer install --prefer-dist`. All discarded when the builder stage
# ends — none of these reach the runtime image.
#   libgmp-dev      → gmp (Web Push crypto fast path)
#   libpq-dev       → pdo_pgsql (production database driver)
#   unzip + git     → composer install --prefer-dist (unzip extracts
#                     ZIP archives from packagist; git clones any
#                     source-only fallback packages)
#
# NOTE: pdo_sqlite is deliberately absent. It's statically compiled into
# php:8.4-cli — `php -m` shows it before any docker-php-ext-install,
# and docker-php-ext-enable's "already loaded" branch skips generating
# an ini for it. libsqlite3-* packages are similarly unnecessary; the
# base image bundles what the static compile needs. See DOCS #68.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgmp-dev \
        libpq-dev \
        unzip \
        git \
    && docker-php-ext-install \
        gmp \
        pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

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
# (DOCS #58 postmortem).
RUN touch /app/.env


# ────────────────────────────────────────────────────────────────────
# Stage 2: runtime — the deployed image. Only runtime shared libs +
# extension .so files copied from builder + app + vendor.
# ────────────────────────────────────────────────────────────────────

FROM php:8.4-cli AS runtime

# Runtime-only apt: shared object libs the extensions link against.
# libgmp10 / libpq5 are the Debian trixie runtime split of the -dev
# packages the builder used. libsqlite3 runtime lib is bundled with the
# base php:8.4-cli image (pdo_sqlite is statically compiled), no separate
# package needed.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgmp10 \
        libpq5 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Extension .so files + auto-generated docker-php-ext-*.ini configs from
# the builder. BuildKit resolves both `FROM php:8.4-cli` clauses to the
# same image ID at graph-construction time, so the extension-API-version
# dir path is guaranteed identical between builder + runtime for the
# duration of one docker build invocation. Copying the whole dirs picks
# up opcache + sodium (base-image-provided) automatically.
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/     /usr/local/etc/php/conf.d/

# Composer binary for ops-side commands inside the running container
# (`docker exec mishka-app composer outdated`).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# v0.7.3 — persistent session storage. Must live in the runtime stage
# because the /var/lib/mishka/sessions directory has to exist in the
# final image for docker-compose's named volume mount to land on a
# writable target.
#
# v0.8.5 — CLOSED the v0.7.6 mode-733 tripwire: container now runs as
# www-data (see USER directive at end of file). Sessions dir owned by
# www-data:www-data at mode 700 (owner-only rwx) — tightest posture
# that matches the exclusive USER. Debugging still possible via
# `docker exec -u root ...` (root ignores mode bits).
#
# v0.7.7 — extend session lifetime from PHP's 24-min default to 30 days
# so the family app "stays logged in" across days rather than requiring
# re-auth every browsing session. Safe because v0.7.0's /me/sessions
# UI gives per-device revoke. See DOCS #69.
#   session.gc_maxlifetime = 2592000  (30 * 24 * 60 * 60 seconds)
# Paired with `new Session(['lifetime' => 2592000])` in bootstrap.php
# which sets the browser cookie's Max-Age. Server-side GC threshold +
# client-side cookie lifetime kept in sync.
RUN mkdir -p /var/lib/mishka/sessions \
    && chown www-data:www-data /var/lib/mishka/sessions \
    && chmod 700 /var/lib/mishka/sessions \
    && printf 'session.save_path = "/var/lib/mishka/sessions"\nsession.gc_maxlifetime = 2592000\n' \
       > /usr/local/etc/php/conf.d/mishka-sessions.ini

# v0.7.6 — pin default_socket_timeout to 5s. In practice this only
# affects Symfony Mailer's EsmtpTransport (via stream_socket_client)
# because libpq (pdo_pgsql) uses direct BSD sockets and Guzzle/WebPush
# use ext-curl (CURLOPT_TIMEOUT). Closes DOCS #67's flagged v0.7.6+
# candidate. See DOCS #68.
RUN echo 'default_socket_timeout = 5' \
    > /usr/local/etc/php/conf.d/mishka-socket-timeout.ini

# App + vendor from builder. /app/.env stub travels with the tree.
# Container env vars still arrive via compose's env_file: [.env].
WORKDIR /app
COPY --from=builder /app /app

EXPOSE 8080

# v0.8.5 — drop root at the runtime-stage boundary. All previous RUNs
# (composer install in builder, apt installs, conf.d writes, mkdir +
# chown of the sessions dir) needed root; from here on the container
# runs as www-data (uid=33 gid=33, Debian default in php:8.4-cli — no
# useradd needed). Applies to BOTH commands (app: `php -S ...`; worker:
# `php vendor/bjornbasar/karhu/bin/karhu push:worker`) because both
# services share this image and neither overrides USER in compose.
#
# Port 8080 is non-privileged (>1024) — no CAP_NET_BIND_SERVICE
# capability needed. Karhu-queue Worker's SIGTERM handling is
# UID-agnostic (no pcntl/posix). DB writes only in the worker path;
# no filesystem writes needed anywhere at runtime.
#
# Dev on Ruxa (bind-mount) overrides this back to `user: "0:0"` in
# /data/personal/docker-compose.yml on the mishka-dev-app service ONLY
# so `docker exec mishka-dev-app composer install / phpunit` keeps
# working against the ubuntu:ubuntu-owned host bind-mount. Dev-worker
# keeps USER www-data (DB-only, no /app writes).
#
# Ops note: `docker exec mishka-app composer …` fails as www-data
# because /var/www (www-data's HOME) is root-owned so the composer
# cache can't write. Use `docker exec -u root mishka-app composer …`
# for interactive composer sessions on the prod container. See DOCS #75.
USER www-data

# mishka-app default: serve via PHP's built-in dev server on :8080.
# mishka-worker overrides this in docker-compose.yml with the karhu CLI
# (php vendor/bjornbasar/karhu/bin/karhu push:worker).
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
