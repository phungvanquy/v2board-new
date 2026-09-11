# Proposal: Deploy with Docker Compose

## Why

V2Board currently has no containerized deployment. Installation requires manually provisioning PHP, MySQL, Redis, and queue workers on the host (`init.sh`/`update.sh` assume a bare-metal environment with `php`, `composer`, `git`, optional `bt` panel). This makes local development, CI, and production onboarding slow and error-prone. A `docker-compose.yml` with a companion `Dockerfile` would let any contributor run `docker compose up` and get a working stack.

## What Changes

- Add `Dockerfile` (PHP 8.2-fpm base, system deps, Composer install, Laravel bootstrap) and `docker-compose.yml` orchestrating the stack.
- Add `.docker/` support files: `nginx` vhost, `php.ini` overrides, entrypoint script.
- Add `.env.docker.example` (or document `.env` overrides) so containers wire correctly (`DB_HOST=db`, `REDIS_HOST=redis`, etc.).
- Services:
  - `app` — PHP-FPM + Nginx (or two containers: `php` + `nginx`; see Design for decision).
  - `db` — MySQL 8 (volume-persisted, healthcheck, `install.sql` auto-import on first boot).
  - `redis` — Redis 7 (volume-persisted, used for cache/queue/session).
  - `horizon` / `queue` — Laravel Horizon worker (runs `php artisan horizon`, restartable).
- Optional `webman` profile/service for the Workerman high-performance HTTP path (`php -c cli-php.ini webman.php`).
- Document usage: `docker compose up -d`, first-time install (`v2board:install` vs. env-driven auto-install), `v2board:update`, logs, and backup/restore.

## Capabilities

### New Capabilities
- `docker-deploy`: One-command Docker Compose deployment for V2Board — image build, service orchestration, env wiring, healthchecks, data persistence, and queue startup.

### Modified Capabilities
- (none) — existing specs are unaffected; this change is additive deployment tooling.

## Impact

- **New files**: `Dockerfile`, `docker-compose.yml`, `.docker/nginx/`, `.docker/php/`, `.docker/entrypoint.sh`, `.env.docker.example` (or docs), `README` docker section.
- **No runtime code changes** to `app/`, `config/`, or `routes/` beyond what is strictly needed for container compatibility (e.g., `APP_URL` / trusted proxies) — called out explicitly in Design if required.
- **Dependencies**: Docker Engine + Compose v2 on the host; no new PHP/JS dependencies.
- **Breaking**: None. Bare-metal `init.sh` flow remains functional.
- **Risks**: Image size, build time, and secret handling (`.env` must not be baked into the image). Mitigated by multi-stage build, `.dockerignore`, and runtime env injection.
