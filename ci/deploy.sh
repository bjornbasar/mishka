#!/usr/bin/env bash
# mishka local CI/CD deploy. Full test gate (phpunit + phpstan + pg-smoke against a
# throwaway postgres) in the `test` image stage, builds the self-contained `runtime`
# image, pushes the local registry (:latest + :sha-), then deploys to Casal with the
# v0.8.5 non-root chown dance + karhu migrate + tracker seeds/backfill.
# Moved here from Bosco 2026-10-22 (CVE-2026-109618/109619 PHP patch + Bosco's SD-card
# I/O ceiling hit deploying it — see mishka/DOCS.md and the lumra-dongle-era deploys
# that hit the same class of problem). mishka originally ran on Casal before an
# app-tier rebalance moved it to Bosco on 2026-06-29; this reverses that move.
# ghcr is published by GitHub-hosted docker-build-push.yml on v* — NOT here.
# Runs on Ruxa via `git push den main` (post-receive) or by hand.
# ci: detach — phpunit + phpstan + pg-smoke against a throwaway postgres: 371s avg, 523s max.
set -euo pipefail
source "${CI_LIB:-/data/git/ci-lib.sh}"

CASAL="ubuntu@192.168.4.6"
CASAL_DIR="/data/mishka"
IMG="$REGISTRY/mishka-php"
PG="mishka-pgtest"
DSN="pgsql:host=$PG;port=5432;dbname=mishka_test"

ci_trap "→ Casal (hub.twobots.dev)"
ci_lock

ci_log "build builder image (php + ext + composer toolchain)"
# --pull: without it, `FROM php:8.4-cli` resolves against whatever layer is already
# cached on the build host, which can silently sit on a pre-patch PHP point release
# indefinitely -- exactly what happened with CVE-2026-109618/109619 (fixed upstream
# in php:8.4-cli 2026-10-21, but mishka kept running the cached 8.4.23 underneath
# until this was noticed and fixed by hand on 2026-10-22). A floating base tag is
# only a real update channel if every build actually checks for a newer image.
docker build --pull --target builder -t mishka-php:builder .

# Tests are .dockerignore'd out of the image (tests/, phpunit.xml.dist,
# phpstan.neon.dist — kept out of the lean runtime), so run them GitHub-style:
# mount the full source (the git-archive build tree HAS the tracked tests) into the
# builder toolchain and composer-install WITH dev deps. vendor lands in the build
# dir (itself .dockerignore'd, so it can't leak into the runtime image build).
ci_log "test gate: composer install + phpunit + phpstan + docs-check (mounted source)"
docker rm -f ci-mishka >/dev/null 2>&1 || true
docker run --rm --name ci-mishka --user "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/.composer \
  -v "$PWD:/app" -w /app mishka-php:builder sh -c \
  'composer install --no-interaction --prefer-dist && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=512M && composer docs-check'

ci_log "pg-smoke against throwaway postgres:16"
docker rm -f "$PG" >/dev/null 2>&1 || true
docker run -d --name "$PG" --network webapps \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=mishka_test postgres:16 >/dev/null
# v0.8.7.2: wait for the postmaster to accept CLIENT connections, not just
# report "ready" locally.  pg_isready returned true too early on 2026-08-19's
# first deploy attempt (postgres:16 on Bosco-scale SD-slow container start),
# after which the `karhu migrate` step immediately hit "Connection refused".
# `psql -c 'SELECT 1'` is a real wire round-trip and succeeds only when the
# postmaster is fully accepting queries.  Same 30s ceiling; no behaviour
# change on the happy path.
for _ in $(seq 1 30); do if docker exec "$PG" psql -U postgres -c 'SELECT 1' >/dev/null 2>&1; then break; fi; sleep 1; done
docker rm -f ci-mishka-pgsmoke >/dev/null 2>&1 || true
docker run --rm --name ci-mishka-pgsmoke --user "$(id -u):$(id -g)" -e HOME=/tmp --network webapps -v "$PWD:/app" -w /app \
  -e DB_DSN="$DSN" -e DB_USER=postgres -e DB_PASS=postgres \
  mishka-php:builder sh -c 'php vendor/bjornbasar/karhu/bin/karhu migrate && vendor/bin/phpunit --filter '\''PgSmoke'\'''
docker rm -f "$PG" >/dev/null 2>&1 || true

ci_log "build + push runtime image: $IMG (:latest + :sha-$CI_SHA)"
docker build --pull --target runtime -t "$IMG:latest" -t "$IMG:sha-$CI_SHA" .
docker push "$IMG:latest"
docker push "$IMG:sha-$CI_SHA"

# Per-target-host deploy lock (homelab#13 follow-on): different hosts no longer queue
# behind each other for the SSH/restart step — only deploys sharing THIS host do.
ci_build_unlock
ci_deploy_lock "casal"

ci_log "decrypt env + ship compose to Casal"
ci_decrypt_env .env.enc .env
rsync -a .env docker-compose.yml "$CASAL:$CASAL_DIR/"

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
ci_log "deploy on Casal (pull + rolling recreate)"
ssh "$CASAL" "cd $CASAL_DIR && docker compose pull && docker compose up -d --remove-orphans"

ci_log "post-flight: assert www-data, migrate, seed foods/exercises, badges backfill"
ssh "$CASAL" "docker exec mishka-app id www-data | grep -q 'uid=33' && docker exec mishka-app whoami | grep -qx 'www-data'"
ssh "$CASAL" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu migrate --applied-by=ci-deploy"
ssh "$CASAL" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu tracker:seed-foods"
ssh "$CASAL" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu tracker:seed-exercises"
ssh "$CASAL" "docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu tracker:badges-backfill"

ci_deploy_unlock

# ---------------------------------------------------------------------------------------
# docs: the hub.twobots.dev user guide (mishka#26). A SECOND, independent deploy to a
# DIFFERENT target host (Lumra, not Casal) — runs after the app's own deploy above
# succeeds, so a docs-only failure never rolls back a working app deploy, but still fails
# this script (and alerts Slack) rather than silently skipping. The DRIFT GATE for this
# guide already ran above, in phase 1 (composer docs-check) — an undocumented/stale-doc
# change never reaches this point.
#
# PREFIXED filenames throughout (docs.Dockerfile, docs.nginx.conf,
# docker-compose.docs.yml) — this repo already owns Dockerfile and docker-compose.yml for
# the real app; see docs.Dockerfile's header for the wojtek/istrbuddy incident that made
# this a hard rule, not a style preference.
LUMRA="ubuntu@192.168.4.38"
LUMRA_DIR="/data/hub-docs"
DOCS_IMG="$REGISTRY/hub-docs"
DOCS_PORT=8109

# Re-acquire the build lock for the docs image — released above once the app's own image
# was built+pushed (homelab#13 follow-on: the global build lock no longer needs to be
# held through an SSH/restart step, but a NEW build still needs it).
ci_lock

ci_log "render the user guide (mkdocs build --strict)"
rm -rf site
ci_mkdocs build --strict

# 9 real pages (home + getting-started + 7 guide pages) + the 404 mkdocs-material emits —
# extra headroom, not counted on. A nav regression that silently dropped a page would
# otherwise deploy happily.
ci_assert_mkdocs_built 9
ci_log "rendered $PAGES pages"

LINKED=$(grep -rl 'class="docs-up"' site --include='*.html' | wc -l)
[ "$LINKED" = "$PAGES" ] || ci_die "only $LINKED of $PAGES pages carry the 'All documentation' link — check overrides/main.html and theme.custom_dir"
ci_log "every page routes back to the index (correct): $LINKED/$PAGES"

ci_assert_mkdocs_canonical "https://docs.twobots.dev/hub/"
ci_log "canonical names the public host (correct)"

ci_log "build + push multi-arch: $DOCS_IMG (:latest + :sha-$CI_SHA)"
ci_ensure_buildx
ci_buildx_push "$DOCS_IMG" -f docs.Dockerfile

ci_build_unlock
ci_deploy_lock "lumra"

ci_log "sync compose + redeploy on Lumra"
ssh "$LUMRA" "mkdir -p $LUMRA_DIR"
# Explicit rename on rsync — the local file is docker-compose.docs.yml, never plain
# docker-compose.yml (that name is already the app's, two directories up from here).
rsync -a docker-compose.docs.yml "$LUMRA:$LUMRA_DIR/docker-compose.yml"
ssh "$LUMRA" "cd $LUMRA_DIR && docker compose pull && docker compose up -d --remove-orphans && docker image prune -f"
ci_wait_http "$LUMRA" "http://localhost:$DOCS_PORT/hub/"

ci_log "smoke-test the deployed docs container"
for PAGE in /hub/ /hub/getting-started/ /hub/calendar/ /hub/chores/ /hub/health/ /hub/household/ /hub/account/ /hub/notifications/ /hub/help/; do
  CODE=$(ssh "$LUMRA" "curl -s -o /dev/null -w '%{http_code}' http://localhost:$DOCS_PORT$PAGE")
  [ "$CODE" = "200" ] || ci_die "$PAGE returned $CODE — the docs site did not deploy correctly"
  ci_log "serves (correct): $PAGE → 200"
done

CODE=$(ssh "$LUMRA" "curl -s -o /dev/null -w '%{http_code}' http://localhost:$DOCS_PORT/hub/search/search_index.json")
[ "$CODE" = "200" ] || ci_die "search_index.json → HTTP $CODE — site search is broken"
ci_log "search index resolves (correct)"

# THE ALLOWLIST ASSERTION. The build context is the whole mishka repo — a regression in
# docs.Dockerfile's COPY list would publish the app's source, vendor/, or the internal
# docs/ architecture notes (a DIFFERENT directory from this guide's own guide/).
ci_assert_not_served "$LUMRA" "$DOCS_PORT" \
  /app/Controllers/HomeController.php /composer.json /mkdocs.yml /docs.Dockerfile \
  /tools/check-docs.php /docs/USERGUIDE.md /guide/index.md /hub/../composer.json

# THE PATH-ROUTING ASSERTION. mkdocs links pages as directory URLs, so nginx 301s
# /hub/chores to add a slash. With absolute_redirect off that Location is
# root-relative — relative to the ORIGIN, not to any proxied prefix. It must therefore
# already CONTAIN /hub/, or the redirect would throw visitors out of this site and
# into whatever else answers at docs.twobots.dev/chores/.
#
# Reads the RAW Location header on purpose: curl's %{redirect_url} resolves a relative
# header against the request URL, so it always looks correct and can never distinguish
# the two cases.
ci_log "assert the trailing-slash redirect keeps the /hub/ prefix"
LOC=$(ssh "$LUMRA" "curl -s -D- -o /dev/null http://localhost:$DOCS_PORT/hub/chores | grep -i '^location:' | tr -d '\r'")
case "$LOC" in
  *"://"*)         ci_die "the redirect is absolute ($LOC) — absolute_redirect must be off" ;;
  *"/hub/chores/"*) ci_log "redirect keeps the prefix (correct): '$LOC'" ;;
  *)               ci_die "redirect LOST the /hub/ prefix: '$LOC' — the site must be rooted at /hub/ in the image" ;;
esac

# Non-fatal by design: Cloudflare/Ayula can lag a container swap by a few seconds, and a
# deploy that succeeded on the origin should not go red in #duskana for that.
ci_log "verify the public URL (non-fatal)"
PUB=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://docs.twobots.dev/hub/ 2>/dev/null || echo 000)
if [ "$PUB" = "200" ]; then
  ci_log "public (correct): https://docs.twobots.dev/hub/ → 200"
else
  ci_log "⚠ https://docs.twobots.dev/hub/ → $PUB (origin is healthy; check the Ayula vhost)"
fi

# The gated hostname must NOT answer 200 to an anonymous request. A 302 to the Cloudflare
# Access login is the CORRECT result, and is also why this path is probed at the origin
# rather than through the edge — blackbox follows redirects and would record a false 200.
ci_log "verify the gated hostname still gates (non-fatal)"
GATED=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://docs.bjornbasar.com/hub/ 2>/dev/null || echo 000)
case "$GATED" in
  302|401|403) ci_log "gated (correct): docs.bjornbasar.com/hub/ → $GATED" ;;
  200)         ci_log "⚠ docs.bjornbasar.com/hub/ answered 200 ANONYMOUSLY — Cloudflare Access is not covering this path" ;;
  *)           ci_log "⚠ docs.bjornbasar.com/hub/ → $GATED" ;;
esac

# Best-effort ghcr mirror. mishka IS public (the only one of the five phase-3 apps that
# is), so unlike artio/hartza's private-repo mirrors this one is a genuine public showcase.
ci_ghcr_copy_best_effort hub-docs -f docs.Dockerfile

ci_deploy_unlock
