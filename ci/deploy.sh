#!/usr/bin/env bash
# mishka local CI/CD deploy. Full test gate (phpunit + phpstan + pg-smoke against a
# throwaway postgres) in the `test` image stage, builds the self-contained `runtime`
# image, pushes the local registry (:latest + :sha-), then deploys to Bosco with the
# v0.8.5 non-root chown dance + karhu migrate + tracker seeds/backfill.
# ghcr is published by GitHub-hosted docker-build-push.yml on v* — NOT here.
# Runs on Ruxa via `git push ruxa main` (post-receive) or by hand.
set -euo pipefail
source "${CI_LIB:-/data/git/ci-lib.sh}"

BOSCO="ubuntu@192.168.4.34"
BOSCO_DIR="/data/mishka"
IMG="$REGISTRY/mishka-php"
PG="mishka-pgtest"
DSN="pgsql:host=$PG;port=5432;dbname=mishka_test"

ci_trap "→ Bosco (hub.twobots.dev)"
ci_lock

ci_log "build builder image (php + ext + composer toolchain)"
docker build --target builder -t mishka-php:builder .

# Tests are .dockerignore'd out of the image (tests/, phpunit.xml.dist,
# phpstan.neon.dist — kept out of the lean runtime), so run them GitHub-style:
# mount the full source (the git-archive build tree HAS the tracked tests) into the
# builder toolchain and composer-install WITH dev deps. vendor lands in the build
# dir (itself .dockerignore'd, so it can't leak into the runtime image build).
ci_log "test gate: composer install + phpunit + phpstan (mounted source)"
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/.composer \
  -v "$PWD:/app" -w /app mishka-php:builder sh -c \
  'composer install --no-interaction --prefer-dist && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=512M'

ci_log "pg-smoke against throwaway postgres:16"
docker rm -f "$PG" >/dev/null 2>&1 || true
docker run -d --name "$PG" --network webapps \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=mishka_test postgres:16 >/dev/null
for _ in $(seq 1 30); do if docker exec "$PG" pg_isready -U postgres >/dev/null 2>&1; then break; fi; sleep 1; done
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp --network webapps -v "$PWD:/app" -w /app \
  -e DB_DSN="$DSN" -e DB_USER=postgres -e DB_PASS=postgres \
  mishka-php:builder sh -c 'php vendor/bjornbasar/karhu/bin/karhu migrate && vendor/bin/phpunit --filter '\''PgSmoke'\'''
docker rm -f "$PG" >/dev/null 2>&1 || true

ci_log "build + push runtime image: $IMG (:latest + :sha-$CI_SHA)"
docker build --target runtime -t "$IMG:latest" -t "$IMG:sha-$CI_SHA" .
docker push "$IMG:latest"
docker push "$IMG:sha-$CI_SHA"

ci_log "decrypt env + ship compose to Bosco"
ci_decrypt_env .env.enc .env
rsync -a .env docker-compose.yml "$BOSCO:$BOSCO_DIR/"

# v0.8.7: retired the alpine chown/chmod dance that ran between stop and up-d.
# It was added for v0.8.5's non-root migration (2026-07-19, DOCS #75) to force
# ownership of the mishka-sessions volume to uid=33 on the first non-root deploy.
# It was designed to stay idempotent forever, but at family scale the sessions
# volume accumulated 191k files (~3169/day bot-scan churn pre-v0.8.7 layout fix
# — DOCS #77), which turned the chown -R walk into a ~2h blocker on Bosco's SD
# card at ~45 IOPS. On 2026-08-10 it hung a Dependabot redeploy and served 502s
# for the family. The chown is a permanent no-op-in-effect now — sessions dir
# has been www-data:www-data 700 continuously since v0.8.5 landed 3+ weeks ago,
# with no path in the app that would change it back.
#
# Safety net if this assumption ever breaks: `docker exec -u root mishka-app
# chown -R www-data:www-data /var/lib/mishka/sessions && chmod 700 …` inside
# the running container is the manual escape hatch.
ci_log "deploy on Bosco (pull + rolling recreate)"
ssh "$BOSCO" "cd $BOSCO_DIR && docker compose pull && docker compose up -d --remove-orphans"

ci_log "post-flight: assert www-data, migrate, seed foods/exercises, badges backfill"
ssh "$BOSCO" "docker exec mishka-app id www-data | grep -q 'uid=33' && docker exec mishka-app whoami | grep -qx 'www-data'"
ssh "$BOSCO" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu migrate --applied-by=ci-deploy"
ssh "$BOSCO" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu tracker:seed-foods"
ssh "$BOSCO" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu tracker:seed-exercises"
ssh "$BOSCO" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu tracker:badges-backfill"
