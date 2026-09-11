# Design: Deploy with Docker Compose

## Context

V2Board is a Laravel 8 app (PHP ^7.3 || ^8.0) with MySQL, Redis (cache/queue/session), and Laravel Horizon for queue supervision. Today it runs bare-metal: `init.sh` installs Composer deps and runs `php artisan v2board:install` (interactive `.env` + `database/install.sql` import); `update.sh` pulls from `origin/master` and runs `v2board:update`; `cli-php.ini` is a tuned PHP-CLI config used by the optional Workerman adapter (`webman.php`, `Adapterman`) that serves HTTP on :6600 with `MAX_REQUEST=6600` and `ncpu*2` workers; `pm2.yaml` runs `php artisan horizon`. There is no `Dockerfile` or `docker-compose.yml` (`.gitignore` already ignores `docker-compose.yml` — that entry must be re-evaluated). Public entry is `public/index.php`; Horizon dashboard is at `/monitor` (middleware `admin`). Config is file+env driven (`config/database.php`, `config/cache.php`, `config/queue.php`, `config/horizon.php`).

See `proposal.md` for motivation.

## Goals / Non-Goals

**Goals:**

- `docker compose up -d` brings up a working stack on a host with only Docker + Compose.
- Single `Dockerfile` with pinned PHP 8.2 base; multi-stage where it pays for itself (Composer deps).
- Env-only configuration; no secrets baked into the image; `.dockerignore` excludes `.env`, `vendor` bloat, `.git`, `node_modules`.
- Healthchecks + startup ordering so migrations/Horizon do not race an unready MySQL/Redis.
- Named volumes for `db`, `redis`, and Laravel writable paths; `install.sql` auto-import on first boot only.
- Horizon as a supervised container; logs to stdout so `docker compose logs` works.

**Non-Goals:**

- Kubernetes/Helm, Swarm, or multi-host orchestration.
- Replacing bare-metal `init.sh`/`update.sh` — they remain functional.
- Automatic TLS/Let's Encrypt inside the stack (operator terminates TLS at their edge; optional Caddy/Nginx TLS profile is deferred).
- Building the `admin-i18n` pipeline inside the image (pre-built `public/assets/admin` ships as-is).

## Decisions

### D1 — Base image and PHP version

**Decision:** `php:8.2-fpm-alpine` as the runtime base (pinned by digest or minor), with `nginx:stable-alpine` as the web tier.

**Rationale:** Project requires `^7.3 || ^8.0`; 8.2 is the newest 8.x still compatible with Laravel 8 and the installed deps (`laravel/framework ^8.0`, `horizon ^5.9.6`). Alpine keeps the image small. Using `php-fpm` matches the `public/index.php` entry and is the conventional Laravel container path; `php:8.2-cli` would be needed only for the Webman path (see D3).

**Alternatives:** `php:8.1-fpm` (also valid but EOL sooner), `php:8.2-apache` (simpler single-container but couples php+httpd and deviates from the nginx config operators already use with V2Board).

### D2 — Container topology

**Decision:** Three mandatory services + one worker:

- `app` — PHP-FPM (builds from `Dockerfile`).
- `nginx` — Nginx reverse-proxy to `app:9000`, serves `public/` directly, forwards PHP to FPM. Config lives in `.docker/nginx/default.conf`.
- `db` — `mysql:8.0` with `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD` from env, `healthcheck: mysqladmin ping`, volume `db_data`.
- `redis` — `redis:7-alpine` with optional `requirepass`, volume `redis_data`, healthcheck `redis-cli ping`.
- `horizon` — same image as `app`, command `php artisan horizon`, `depends_on: db healthy, redis healthy`, restart policy `unless-stopped`.

**Rationale:** Separating `nginx` and `php-fpm` is the standard Laravel deployment and lets Nginx serve static assets without hitting PHP. A single `app` container that runs both Nginx and FPM is possible but complicates signal handling and healthchecks. Horizon must be a distinct container so it can be scaled/restarted independently.

**Alternatives:** Single-container `app` with supervisord (simpler `docker-compose.yml` but harder to observe and violates single-process-per-container). Two-container `app+nginx` combined via `php:8.2-fpm` + nginx installed in same image — workable for minimal deployments but rejected for clarity.

### D3 — Webman / high-performance HTTP

**Decision:** Ship a Compose **profile** `webman` (or optional service `webman`) gated behind `profiles: ["webman"]`, not enabled by default. It runs `php -c cli-php.ini webman.php` (requires `pcntl` + `adapterman` on PHP 8, already conditionally installed by `init.sh`). Default path remains `nginx → php-fpm`.

**Rationale:** Webman is an optimization, not the default deployment. Making it a profile avoids forcing `pcntl`/`workerman` on every build while still giving operators who need it a one-flag enable.

### D4 — Entrypoint and startup ordering

**Decision:** ` .docker/entrypoint.sh` (POSIX sh) does:

1. Wait for `db` and `redis` (TCP probe loop with timeout, plus `mysqladmin ping` if available).
2. If `.env` does not exist, copy `.env.docker.example` → `.env` and generate `APP_KEY` if empty (`php artisan key:generate --force`).
3. `php artisan config:clear` (never `config:cache` at build time — env is runtime).
4. If `DB` is reachable and `migrations` table is empty, import `database/install.sql` *or* run `php artisan migrate --force` (prefer the project's `v2board:install` flow where non-interactive; see Open Questions). Subsequent boots skip this.
5. `php artisan storage:link` if needed, `chown`/`chmod` for `storage/` and `bootstrap/cache/`.
6. `exec php-fpm` (or `exec nginx -g 'daemon off;'` in the nginx container; Horizon container `exec`s `php artisan horizon`).

Compose uses `depends_on: condition: service_healthy` for `db`/`redis` → `app`/`horizon`. No `config:cache` at build time.

**Rationale:** Laravel's `config:cache` bakes env at build time and breaks runtime overrides — a common Docker pitfall. Migrations must not race an unready DB; healthchecks + wait loop covers both Compose v2 semantics and hosts where `condition: service_healthy` is available.

### D5 — Environment and secrets

**Decision:** `.env.docker.example` at repo root documents every variable the stack needs with placeholders. `docker-compose.yml` uses `env_file: [.env]` plus explicit `environment:` overrides for `DB_HOST=db`, `REDIS_HOST=redis`, etc., so an existing `.env` is respected but container hostnames are forced. Never `COPY .env` in the Dockerfile; `.dockerignore` excludes `.env`, `.env.backup`, `vendor`, `.git`, `node_modules`, `storage/logs/*`, `admin-i18n/node_modules`.

**Rationale:** Keeps secrets out of the image and preserves the existing `.env` contract (`V2boardInstall` writes to it). Operators who already have a configured `.env` should not need a new file.

### D6 — Persistence

**Decision:** Named volumes `db_data`, `redis_data`, plus a volume or bind-mount for `storage/` (or at minimum `storage/logs` and `storage/framework`). `public/storage` symlink target lives under `storage/app/public`. Host bind-mount of the whole repo is for development; for production the image `COPY`s the app and volumes handle persistence.

**Rationale:** Prevents data loss on `docker compose down` / rebuild. Matches the bare-metal expectation that `storage/` is writable.

### D7 — Build

**Decision:** Multi-stage `Dockerfile`:

- Stage `vendor`: `composer:2` image runs `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` with `composer.json`/`composer.lock` cached.
- Stage `app`: `php:8.2-fpm-alpine` installs system deps (`libzip-dev`, `libpng-dev`, `onigmb-dev`, `icu-dev`, `libxml2-dev` as needed for `pdo_mysql`, `gd`, `zip`, `bcmath`, `pcntl`, `redis` via `pecl`), copies `vendor/` from stage 1, copies app source, sets `WORKDIR`.

No `npm`/`vite` build step — frontend `public/assets/admin` is pre-built and checked in (the `admin-i18n` pipeline is unrelated to Docker).

### D8 — Observability

**Decision:** PHP-FPM and Nginx log to stdout/stderr; Horizon logs to stdout. `docker-compose.yml` sets `logging: { driver: json-file, options: { max-size: "10m" } }` or leaves default. Healthcheck for `app` is `curl -f http://localhost/` or `php-fpm-healthcheck` if available.

## Risks / Trade-offs

- **`.gitignore` ignores `docker-compose.yml`** → `git status` will hide the new file; fix by removing that line or scoping it to `docker-compose.override.yml` only. Mitigation: edit `.gitignore` as part of the change.
- **`v2board:install` is interactive** (prompts for DB host/name/user/pass, admin email) → non-interactive container init must either drive it via env vars or bypass it and run the SQL import + admin seed directly. Mitigation: entrypoint reads `DB_*` and `ADMIN_EMAIL`/`ADMIN_PASSWORD` from env; if missing, log instructions and exit 0 without seeding.
- **Image size vs. build time** — installing PHP extensions via `pecl`/`docker-php-ext-install` adds build time. Mitigation: pin `apk` deps, leverage BuildKit cache mounts for `composer`.
- **`cli-php.ini` tuning (`opcache.jit`, `opcache.validate_timestamps=0`, `disable_functions`)** is aggressive and CLI-specific — FPM should use a separate `php.ini` with `opcache.validate_timestamps=0` in prod but `1` in dev. Mitigation: provide `.docker/php/php.ini` and `.docker/php/php-fpm.conf` distinct from `cli-php.ini`.
- **MySQL 8 `caching_sha2_password`** breaks older `pdo_mysql` — mitigation: `command: --default-authentication-plugin=mysql_native_password` or ensure `php:8.2`'s `pdo_mysql` supports it.
- **Host port collision** (80/3306/6379) → Mitigation: expose `HOST_PORT` via env (`${APP_PORT:-8080}:80`).

## Migration Plan

1. Add `Dockerfile`, `.docker/`, `docker-compose.yml`, `.env.docker.example`, `.dockerignore` updates, and docs.
2. Remove or narrow the `docker-compose.yml` entry in `.gitignore`.
3. `docker compose build --no-cache` → `docker compose up -d` → verify `GET /` and `/<secure_path>` admin.
4. Rollback: `docker compose down -v` (or without `-v` to keep data) and resume bare-metal via `init.sh`; no DB migration is irreversible beyond what the app itself does.

## Open Questions

- **Q1:** Should the default `docker-compose.yml` bind-mount the repo for live-code development, or `COPY` for production fidelity? **Resolved during apply:** `COPY` by default; `docker-compose.override.yml.example` provides the bind-mount (`.: /var/www`) for dev, and `docker compose config` was verified to show the bind only when the override is present.
- **Q2:** Exact non-interactive admin seeding — reuse `V2boardInstall::registerAdmin` logic via `php artisan tinker` one-liner, or `INSERT` directly? **Resolved during apply:** `.docker/seed-admin.php` boots Laravel (`vendor/autoload.php` + `bootstrap/app.php` + console Kernel) and mirrors `V2boardInstall::registerAdmin` (email, `password_hash`, `Helper::guid(true)` uuid, `Helper::guid()` token, `is_admin=1`). It is idempotent (skips when the email exists) and only runs when `ADMIN_EMAIL`/`ADMIN_PASSWORD` are set. No app code was modified for it.

## Implementation Notes (resolved during apply)

Corrections made once the stack was actually built and booted. None change the
spec requirements; they replace assumptions in D1/D2/D4 that the codebase
contradicted.

1. **D1 — PHP version is a build arg with a Composer platform pin.** `ARG PHP_VERSION=8.2` (overridable via `.env`) feeds `FROM php:${PHP_VERSION}-fpm-alpine`. Because `composer.lock` is gitignored, an unpinned `composer install` resolved dependencies demanding PHP >= 8.4.1, which then hard-failed at runtime via `vendor/composer/platform_check.php`. The vendor stage now runs `composer config platform.php "${PHP_VERSION}.0"` so resolution matches the runtime image.
2. **D2 — Nginx is a third build target, not the stock image.** `nginx:stable-alpine` cannot see `public/` unless assets live in a shared volume, and a named volume pre-populated from an image is *not* refreshed on rebuild — an upgrade would silently serve stale admin bundles. The `Dockerfile` therefore has an `nginx` target that does `COPY --from=app /var/www/public`, so assets refresh on every rebuild. `public/storage` is symlinked to `/var/www/storage/app/public` and the `app_storage` volume is mounted read-only into nginx.
3. **D4 — First-boot detection uses the `v2_user` sentinel, not the `migrations` table.** V2Board does not use Laravel migrations (`database/migrations/` holds one unused 2019 file; the schema lives in `database/install.sql` / `database/update.sql`). The entrypoint probes `SHOW TABLES LIKE 'v2_user'`: present → skip import; reachable-but-empty → import; unreachable → skip. `V2BOARD_AUTO_UPDATE=1` opts into `v2board:update` on later boots (off by default, since it terminates Horizon).
4. **D4 — The `mysql` import needs `--skip-ssl`.** Against `mysql:8.0` on the compose network, mariadb-client failed with `ERROR 2026 (HY000): TLS/SSL error: Certificate verification failure`, and the original `cmd | sed` pipeline hid the failure (exit status came from `sed`). Import now captures output separately and re-probes the sentinel afterwards, so a failed import is reported as an error instead of "imported".
5. **D4 — Theme pre-warm is required or the first request is a 500.** `routes/web.php` calls `ThemeService::init()` when `config('theme.default')` is unset. Laravel only loads `config/*.php` at the top level, so `config('theme.default')` resolves *only* from a cached config; `ThemeService::init()` writes `config/theme/default.php`, calls `config:cache`, then spins in `while (true)` until the config resolves — which it cannot within the same request. Observed as `500 请检查V2Board目录权限` then a hang. The entrypoint now writes `config/theme/default.php` from `public/theme/default/config.json` (same mapping as `ThemeService`, without the loop) and runs `config:cache`, so the first HTTP request is already 200 (measured 0.34s).
6. **D4 — `config/` must be writable by `www-data`.** `ThemeService` writes `config/theme/<theme>.php` and `ConfigController` writes `config/v2board.php`. The `app_config` named volume is populated root-owned, so the entrypoint `chown -R www-data:www-data config` on every boot.
7. **D4 — php-fpm's master must stay root.** Dropping the whole entrypoint to `www-data` broke FPM with `failed to open error_log (/proc/self/fd/2): Permission denied`. The entrypoint now runs as root, fixes ownership, and `su-exec`s to `www-data` only for `php`/artisan/horizon; `php-fpm` is exec'd as root with workers as `www-data` (per the pool config).
8. **New constraint — `APP_ENV` must be `local`.** `config/horizon.php` defines supervisors only under `environments.local`. With `APP_ENV=production` Horizon starts with `Supervisors: None` and no job is ever consumed. `.env.docker.example` ships `APP_ENV=local` (matching upstream `.env.example`) with a comment explaining why; `APP_DEBUG=false` still suppresses stack traces.
9. **New constraint — Horizon watches named queues only** (`order_handle`, `traffic_fetch`, `stat`, `send_email`, `send_email_mass`, `send_telegram`). Jobs on `default` are never picked up; the documented queue test dispatches `->onQueue('stat')`.
10. **Added file `app/Jobs/E2EProbe.php`** (a `ShouldQueue` job that writes `E2E_JOB` to the cache) so the Horizon requirement in `docs/docker.md` §Verification is reproducible. It is inert unless dispatched and is not referenced by any route or service provider. This is the one file added under `app/` — flagged here because the proposal said the change would not touch `app/`.

