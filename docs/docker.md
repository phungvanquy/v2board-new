# Docker — V2Board with Compose

> Prerequisites: **Docker Engine 24+** and **Compose v2** (`docker compose version` prints `v2.x`). Nothing else needs to be installed on the host — no PHP, MySQL, or Redis.

## Quick start (production-like)

```bash
cp .env.docker.example .env
# Edit .env — at least set DB_PASSWORD / DB_ROOT_PASSWORD / APP_URL / APP_KEY
# APP_KEY can be left empty: the entrypoint generates it on first boot.
# Optional: ADMIN_EMAIL / ADMIN_PASSWORD to auto-create the first admin.

docker compose up -d --build
# Wait for healthchecks:
docker compose ps
# All services should show (healthy) or Up.

# First request should be 200 (no 5xx on cold boot):
curl -fsS -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/
curl -fsS http://127.0.0.1:8080/healthz   # => ok (nginx-only probe)
```

Admin URL is `http://<host>:${APP_PORT:-8080}/<secure_path>` where `<secure_path>` is `hash('crc32b', config('app.key'))` unless `config('v2board.secure_path')` is set via **Settings → System**. Find yours with:

```bash
docker compose exec app php artisan tinker --execute 'echo "/".(config("v2board.secure_path") ?: config("v2board.frontend_admin_path") ?: hash("crc32b", config("app.key")))."\n";'
```

Guest API smoke test:

```bash
curl -s http://127.0.0.1:8080/api/v1/guest/comm/config | jq .
```

## Services

| Service   | Image / build target | Port | Notes |
|-----------|----------------------|------|-------|
| `app`     | `Dockerfile: app` (`php:${PHP_VERSION}-fpm-alpine`, default 8.2) | `9000` (internal) | PHP-FPM + Laravel. Entrypoint handles `.env`, `APP_KEY`, `storage:link`, theme pre-warm, and idempotent DB import. |
| `nginx`   | `Dockerfile: nginx`  | `${APP_PORT:-8080}:80` | Serves `public/` statically; forwards PHP to `app:9000`. Built from the same context so `public/assets/admin` refreshes on rebuild. |
| `db`      | `mysql:8.0`          | `3306` (internal) | Volume `db_data`. `utf8mb4`, `mysql_native_password` for `pdo_mysql` compat. Healthcheck `mysqladmin ping`. |
| `redis`   | `redis:7-alpine`     | `6379` (internal) | Volume `redis_data`. `--requirepass` only when `REDIS_PASSWORD` is set. |
| `horizon` | same image as `app`  | — | `php artisan horizon`. `restart: unless-stopped`, `SKIP_DB_INIT=1` (DB import is `app`'s job). Logs to stdout. |
| `webman`  | same image as `app`  | `${WEBMAN_PORT:-6600}:6600` | **Profile `webman` only** — `php -c cli-php.ini webman.php` (Workerman/Adapterman). See below. |

Volumes: `db_data`, `redis_data`, `app_storage` (`storage/`), `app_bootstrap_cache`, `app_config` (`config/` — so `config/v2board.php` and `config/theme/*.php` survive restarts).

## Environment

- Copy `.env.docker.example` → `.env` and edit secrets. Never commit `.env` (it is gitignored and excluded from the image via `.dockerignore`).
- `docker-compose.yml` loads `.env` via `env_file` and forces `DB_HOST=db` / `REDIS_HOST=redis` via `environment:` so host-side `.env` values for other deploys do not break the compose network.
- `PHP_VERSION` (default `8.2`) pins both the runtime image and Composer's `platform.php` (so a project without `composer.lock` does not resolve to packages that need PHP 8.4).
- `APP_ENV` **must stay `local`** — `config/horizon.php` defines supervisors only under `environments.local`. With `APP_ENV=production` Horizon starts with zero supervisors and no jobs are processed.
- `APP_PORT` (default `8080`) controls the host mapping. Change it if `8080` collides: `APP_PORT=8081 docker compose up -d`.

## First boot vs. existing data

- **First boot (empty `db_data`):** entrypoint imports `database/install.sql` (the full schema + `database` seeds) via `mysql --skip-ssl` and, when `ADMIN_EMAIL`/`ADMIN_PASSWORD` are set, creates the admin via `.docker/seed-admin.php`. A theme pre-warm writes `config/theme/default.php` and runs `config:cache` so the first HTTP request is already 200.
- **Subsequent boots:** entrypoint probes `SHOW TABLES LIKE "v2_user"` — when the sentinel table exists it skips the import. `V2BOARD_AUTO_UPDATE=1` opts into running `php artisan v2board:update` automatically; otherwise run it manually (next section).
- **Existing `.env`:** respected. The entrypoint only generates `APP_KEY` when the key is empty and only creates `.env` when the file is missing.

## Common operations

```bash
# Logs (all services / single service)
docker compose logs -f
docker compose logs -f app horizon

# Run any artisan command
docker compose exec app php artisan horizon:status
docker compose exec app php artisan queue:failed
docker compose exec app php artisan tinker

# Apply an application update inside the container
git pull
docker compose build app nginx        # rebuild images (preserves db_data!)
docker compose up -d
docker compose exec app php artisan v2board:update   # or: apply database/update.sql manually

# Horizon control
docker compose restart horizon
docker compose logs -f horizon

# Shell
docker compose exec app sh
docker compose exec db mysql -uv2board -p"$DB_PASSWORD" v2board

# Tear down (keep data) / destroy data
docker compose down                   # keeps volumes
docker compose down -v                # destroys db_data + redis_data + app_* volumes
```

### Updating without losing data

`docker compose build app && docker compose up -d` **preserves** `db_data` — verified (see Verification below). Only `down -v` drops volumes. The documented update (`php artisan v2board:update` inside the container) runs idempotently; it does not drop tables.

### Backup and restore

```bash
# Volumes on this host
docker volume ls | grep v2board

# Backup db_data to a tarball (host path ./backups/)
mkdir -p backups
docker run --rm -v v2board_db_data:/data -v "$PWD/backups:/backup" alpine \
  tar czf /backup/db_data-$(date +%F).tgz -C /data .

# Backup Redis (optional)
docker run --rm -v v2board_redis_data:/data -v "$PWD/backups:/backup" alpine \
  tar czf /backup/redis_data-$(date +%F).tgz -C /data .

# Restore (stop the stack first)
docker compose down
docker run --rm -v v2board_db_data:/data -v "$PWD/backups:/backup" alpine \
  sh -c 'rm -rf /data/* && tar xzf /backup/db_data-YYYY-MM-DD.tgz -C /data'
docker compose up -d
```

### Webman / high-performance HTTP (optional)

Workerman via `joanhey/adapterman` (same path `init.sh`/`update.sh` use on PHP 8) is available as an **opt-in** Compose profile — it is not started by default:

```bash
docker compose --profile webman up -d        # starts webman alongside the normal stack
docker compose --profile webman ps
# Exposed as ${WEBMAN_PORT:-6600}:6600 on the host
```

Verify the profile is gated:

```bash
docker compose config | grep -q webman && echo "leaking" || echo "not included by default (ok)"
docker compose --profile webman config | grep -q webman && echo "included with --profile webman (ok)"
```

## Bare-metal deployment is unchanged

`init.sh` / `update.sh` / `cli-php.ini` / `pm2.yaml` continue to work. The Docker path is additive; it does not modify `app/`, `config/`, or `routes/`.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `GET /` → `500 请检查V2Board目录权限` on first request only | `config/theme/default.php` not yet written | Fixed by the entrypoint pre-warm; if you built an old image, `docker compose build app && docker compose up -d` and retry. `docker compose exec app ls -la config/theme/` should show `default.php`. |
| `500` with `Composer detected issues ... require PHP >= 8.4` | Vendor resolved to packages needing a newer PHP | Set `PHP_VERSION=8.2` (default) and rebuild: `docker compose build --no-cache app`. The Dockerfile pins `platform.php` to the target version. |
| Horizon shows `Supervisors: None` and jobs stay queued | `APP_ENV=production` while `config/horizon.php` only defines `environments.local` | Set `APP_ENV=local` (the default in `.env.docker.example`). Horizon's supervisor is keyed by `APP_ENV`. |
| `mysql: ERROR 2026 ... certificate is NOT trusted` | TLS cert mismatch inside the compose network | Fixed — entrypoint uses `mysql --skip-ssl`. Update the image: `docker compose build app`. |
| `Horizon started successfully` but jobs never run | Job dispatched to `default` while Horizon watches `order_handle, traffic_fetch, stat, ...` | Dispatch to a watched queue: `dispatch((new MyJob)->onQueue('stat'))`. |
| Port `8080` already in use | Host collision | `APP_PORT=8081 docker compose up -d` or set `APP_PORT` in `.env`. |

## Development: live code reload

Use the override file so edits on the host are reflected without rebuilding:

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
docker compose up -d        # compose merges the override automatically
```

Without `docker-compose.override.yml` the stack uses the image's `COPY`-baked source (production fidelity). See `docker-compose.override.yml.example` for details.

## Verification (reproduces CI)

```bash
# 1. Fresh build — no secrets baked, no .git in image
docker run --rm --entrypoint sh v2board-app:local -c 'test ! -f /var/www/.env && echo "no .env (ok)"; test ! -d /var/www/.git && echo "no .git (ok)"'

# 2. Cold boot is 200 on the first request (no 500)
docker compose down -v
docker compose up -d --build
curl -fsS -o /dev/null -w "GET / => %{http_code}\n" http://127.0.0.1:8080/   # => 200
curl -fsS http://127.0.0.1:8080/healthz                                        # => ok

# 3. Admin and API are reachable
SP=$(docker compose exec app php artisan tinker --execute 'echo config("v2board.secure_path") ?: config("v2board.frontend_admin_path") ?: hash("crc32b", config("app.key"));' 2>&1 | tail -1)
curl -fsS -o /dev/null -w "GET /$SP => %{http_code}\n" "http://127.0.0.1:8080/$SP"  # => 200
curl -fsS http://127.0.0.1:8080/api/v1/guest/comm/config | jq .

# 4. Horizon processes jobs
docker compose exec app php artisan tinker --execute 'Illuminate\Support\Facades\Cache::forget("E2E_JOB"); dispatch((new App\Jobs\E2EProbe)->onQueue("stat"));'
sleep 3
docker compose exec app php artisan tinker --execute 'echo Illuminate\Support\Facades\Cache::get("E2E_JOB","<pending>");'  # => processed
docker compose logs horizon | grep -E "Processing:.*E2EProbe|Processed:.*E2EProbe"

# 5. Data survives down/up and rebuild
docker compose exec app php artisan tinker --execute 'App\Models\User::create(["email"=>"probe@e2e.test","password"=>password_hash("x",PASSWORD_DEFAULT),"uuid"=>\App\Utils\Helper::guid(true),"token"=>\App\Utils\Helper::guid(),"balance"=>0]);'
docker compose down && docker compose up -d
docker compose exec app php artisan tinker --execute 'echo App\Models\User::where("email","probe@e2e.test")->count();'  # => 1
docker compose build app && docker compose up -d
docker compose exec app php artisan tinker --execute 'echo App\Models\User::where("email","probe@e2e.test")->count();'  # => 1
docker compose exec app php artisan tinker --execute 'App\Models\User::where("email","probe@e2e.test")->delete();'

# 6. Logs surface on stdout
docker compose logs app | grep "\[entrypoint\]"
docker compose logs horizon | grep "Horizon started"
docker compose logs nginx | grep "start worker process"

# 7. Override (dev) vs production (COPY) switching
docker compose config | grep -q "bind.*:/var/www" && echo "bind mount active" || echo "COPY mode (prod)"
cp docker-compose.override.yml.example docker-compose.override.yml
docker compose config | grep -q "bind.*:/var/www" && echo "override: bind mount active"
rm docker-compose.override.yml

# 8. Profile gating
docker compose config | grep -q webman && echo "leak" || echo "webman not in default graph (ok)"
docker compose --profile webman config | grep -q webman && echo "webman with --profile (ok)"
```
