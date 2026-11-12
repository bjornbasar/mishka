# The Mishka Den user guide — static nginx, served at hub.twobots.dev's documentation
# counterpart.
#
# PREFIXED ON PURPOSE (docs.Dockerfile, not Dockerfile): this repo already owns a Dockerfile
# and docker-compose.yml for the application. Deriving docs scaffolding from a docs-only
# repo and keeping unprefixed names overwrote an app's own Dockerfile on wojtek, and
# separately broke istrbuddy's `build: .` context — see mishka#26.
#
# Built multi-arch on Ruxa (amd64 + arm64), pushed to 192.168.4.9:5000, pulled on Lumra.
# Fronted by Ayula at docs.twobots.dev/hub/ (public) and docs.bjornbasar.com/hub/
# (Cloudflare Access). Both names hit THIS container.
#
# `site/` is produced by ci/deploy.sh BEFORE the build (ci_mkdocs in ci-lib.sh):
# mkdocs-material is Python, and running pip under arm64 emulation to produce static HTML
# would be minutes of waste per deploy.
#
# COPY is an explicit ALLOWLIST. The build context is the whole mishka repo, so the PHP app
# source, vendor/, composer files and docs/ (mishka's own internal architecture notes — a
# DIFFERENT directory from this guide's own guide/) must never be served. An allowlist fails
# closed — a new directory in the repo cannot leak by default. ci/deploy.sh asserts the
# important ones 404 after deploy.
FROM nginx:alpine

LABEL org.opencontainers.image.source=https://github.com/bjornbasar/mishka
LABEL org.opencontainers.image.description="Mishka Den — user guide (docs.twobots.dev/hub/)"

COPY docs.nginx.conf /etc/nginx/conf.d/default.conf

# ⚠ THE /hub/ SUBDIRECTORY IS REQUIRED, NOT COSMETIC. The site is served at its real
# public path so MkDocs' trailing-slash redirects stay correct behind a path-routed proxy —
# see the header of docs.nginx.conf. Changing this to `html/` breaks every directory URL.
COPY site/ /usr/share/nginx/html/hub/

EXPOSE 80
