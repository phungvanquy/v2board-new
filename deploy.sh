#!/bin/sh
# Upgrade the compose stack to new images without losing data.
#
# Two variants (pick one per deploy):
#   A. Registry images (tagged releases, e.g. v1.2.3):
#        APP_IMAGE=ghcr.io/<owner>/v2board-app:v1.2.3 \
#        NGINX_IMAGE=ghcr.io/<owner>/v2board-nginx:v1.2.3 \
#        sh deploy.sh
#   B. Local build (track git directly):
#        git pull
#        docker compose build app nginx
#        sh deploy.sh
#
# What the deploy does:
#   1. pull  — no-op for local-build users (warns, continues).
#   2. up db/redis first so the app entrypoint can reach MySQL.
#   3. up app — the entrypoint appends missing .env keys, syncs framework
#      config/*.php from the image (preserving config/v2board.php and
#      config/theme/*), and runs `v2board:update` automatically
#      (opt out with V2BOARD_AUTO_UPDATE=0 in .env).
#   4. recreate horizon/scheduler/nginx so workers run the new code.
#
# Back up before upgrading (in-panel Full export, or volume tarball —
# see docs/docker.md). `docker compose down` keeps volumes; only
# `docker compose down -v` destroys data.
set -eu

if ! docker compose pull 2>/dev/null; then
  echo "deploy: 'compose pull' found nothing to fetch (local-build setup?) — continuing"
fi
docker compose up -d db redis
docker compose up -d app
docker compose up -d --force-recreate horizon scheduler nginx
docker compose ps

# Escape hatch when automatic migration is disabled (V2BOARD_AUTO_UPDATE=0):
#   docker compose exec app php artisan v2board:update
