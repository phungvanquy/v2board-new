#!/bin/sh
# Prepare the persistent key before Compose creates any application services.
# Uses the app image's PHP; no PHP or Composer is needed on the host.
set -eu

cd "$(dirname "$0")"

if [ ! -f .env ]; then
  (umask 077; cp .env.docker.example .env)
  echo "setup: created .env; edit the database passwords and APP_URL before starting the stack"
fi

# Bypass normal startup: this container only prepares .env, without Laravel,
# database initialization, or config caching. Mount the helper so setup also
# works with an already-built image from an earlier release.
docker compose run --rm --no-deps -T --entrypoint php \
  --volume "$PWD/.docker/app-key.php:/var/www/.docker/app-key.php:ro" \
  app /var/www/.docker/app-key.php prepare
