#!/bin/sh
# Entrypoint for app/horizon/webman. Runs as root so it can fix ownership of
# named volumes and write the bind-mounted .env; drops to www-data for artisan
# and Horizon. php-fpm's master stays root (workers run as www-data via the pool
# config) because the default `error_log = /proc/self/fd/2` needs root to open.
set -eu

ROOT="/var/www"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-v2board}"
DB_USERNAME="${DB_USERNAME:-v2board}"
DB_PASSWORD="${DB_PASSWORD:-}"
REDIS_HOST="${REDIS_HOST:-redis}"
REDIS_PORT="${REDIS_PORT:-6379}"
WAIT_TIMEOUT="${WAIT_TIMEOUT:-60}"

log() { printf '[entrypoint] %s\n' "$*"; }

is_root() { [ "$(id -u)" = "0" ]; }

as_app() { # run a command as www-data when we are root, otherwise directly
  if is_root && command -v su-exec >/dev/null 2>&1; then
    su-exec www-data "$@"
  else
    "$@"
  fi
}

artisan() { as_app php artisan "$@" 2>&1 | sed 's/^/[artisan] /' || true; }

# ── 0. Writable dirs (needs root for fresh named volumes / bind-mounts)
mkdir -p "$ROOT/storage/framework/cache" "$ROOT/storage/framework/sessions" \
         "$ROOT/storage/framework/views" "$ROOT/storage/logs" \
         "$ROOT/storage/app/public" "$ROOT/storage/app/database-backups/tmp" \
         "$ROOT/storage/app/database-backups/pre-restore" "$ROOT/bootstrap/cache" 2>/dev/null || true
if is_root; then
  chown -R www-data:www-data "$ROOT/storage" "$ROOT/bootstrap/cache" 2>/dev/null || true
  # ThemeService writes config/theme/<theme>.php and ConfigController writes
  # config/v2board.php at runtime — the whole tree must be www-data writable.
  chown -R www-data:www-data "$ROOT/config" 2>/dev/null || true
  chmod -R ug+w "$ROOT/config" 2>/dev/null || true
fi
chmod -R ug+rwX "$ROOT/storage" "$ROOT/bootstrap/cache" 2>/dev/null || true

# ── 1. Wait for services that actually resolve in this network
resolves() { php -r 'exit(gethostbyname($argv[1]) === $argv[1] ? 1 : 0);' "$1" 2>/dev/null; }

wait_tcp() {
  _host="$1"; _port="$2"; _name="$3"; _i=0
  while [ "$_i" -lt "$WAIT_TIMEOUT" ]; do
    if nc -z "$_host" "$_port" 2>/dev/null; then
      log "$_name ($_host:$_port) is up"; return 0
    fi
    _i=$((_i+1)); sleep 1
  done
  log "WARN: $_name ($_host:$_port) not ready after ${WAIT_TIMEOUT}s — continuing"
  return 0
}
if [ "${SKIP_SERVICE_WAIT:-0}" != "1" ]; then
  resolves "$DB_HOST"    && wait_tcp "$DB_HOST" "$DB_PORT" "db"
  resolves "$REDIS_HOST" && wait_tcp "$REDIS_HOST" "$REDIS_PORT" "redis"
fi

# ── 2. .env — create from template on first boot, normalize CRLF
if [ ! -f "$ROOT/.env" ]; then
  if [ -f "$ROOT/.env.docker.example" ]; then
    cp "$ROOT/.env.docker.example" "$ROOT/.env"
    log "created .env from .env.docker.example"
  elif [ -f "$ROOT/.env.example" ]; then
    cp "$ROOT/.env.example" "$ROOT/.env"
    log "created .env from .env.example"
  fi
fi
if [ -f "$ROOT/.env" ]; then sed -i 's/\r$//' "$ROOT/.env" 2>/dev/null || true; fi

# ── 3. APP_KEY (root, so it can write the bind-mounted .env)
if [ -f "$ROOT/.env" ] && ! grep -qE '^APP_KEY=.{10,}' "$ROOT/.env" 2>/dev/null; then
  log "generating APP_KEY"
  php artisan key:generate --force >/dev/null 2>&1 || log "WARN: key:generate failed"
fi

# ── 4. Laravel bootstrap hygiene (runtime only, never baked into the image)
artisan config:clear
as_app php artisan cache:clear 2>&1 | sed 's/^/[artisan] /' || true
# storage:link — only when the symlink is actually missing (avoid noisy output)
if [ ! -L "$ROOT/public/storage" ]; then
  artisan storage:link
else
  log "public/storage link already exists — skipping storage:link"
fi
# Pre-warm the theme runtime config so the very first HTTP request does not
# trigger ThemeService::init()'s File::put + config:cache + tight loop inside
# the FPM worker. Do it here as www-data while we still have root to fix perms.
for _theme in default; do
  if [ ! -f "$ROOT/config/theme/${_theme}.php" ] && [ -f "$ROOT/public/theme/${_theme}/config.json" ]; then
    log "pre-warming config/theme/${_theme}.php"
    as_app php -r '
      $theme = $argv[1];
      $root = getenv("ROOT") ?: "/var/www";
      $src = "$root/public/theme/$theme/config.json";
      $dst = "$root/config/theme/$theme.php";
      $raw = @file_get_contents($src);
      if ($raw === false) { fwrite(STDERR, "cannot read $src\n"); exit(1); }
      $cfg = json_decode($raw, true);
      if (!isset($cfg["configs"]) || !is_array($cfg["configs"])) { fwrite(STDERR, "bad $src\n"); exit(1); }
      $data = [];
      foreach ($cfg["configs"] as $c) $data[$c["field_name"]] = $c["default_value"] ?? "";
      $php = "<?php\n return " . var_export($data, true) . " ;";
      if (@file_put_contents($dst, $php) === false) { fwrite(STDERR, "cannot write $dst\n"); exit(1); }
      echo "wrote $dst\n";
    ' "$_theme" 2>&1 | sed 's/^/[theme] /' || log "WARN: theme pre-warm failed for $_theme"
  fi
done
# If we pre-warmed any theme, refresh the config cache so the next FPM request
# sees config("theme.<name>") without needing to run ThemeService::init().
if ls "$ROOT/config/theme/"*.php >/dev/null 2>&1; then
  as_app php artisan config:cache 2>&1 | sed 's/^/[artisan] /' || true
fi

# ── 5. Idempotent first-boot DB init (horizon/webman set SKIP_DB_INIT=1)
# Probe exit codes: 0 = schema present, 2 = reachable but empty, 1 = unreachable
probe_schema() {
  php -r '
    $h=getenv("DB_HOST")?:"db"; $p=getenv("DB_PORT")?:"3306";
    $u=getenv("DB_USERNAME")?:"v2board"; $w=getenv("DB_PASSWORD")?:"";
    $d=getenv("DB_DATABASE")?:"v2board";
    try{
      $pdo=new PDO("mysql:host=$h;port=$p;dbname=$d;charset=utf8mb4",$u,$w,[PDO::ATTR_TIMEOUT=>3]);
      $st=$pdo->query("SHOW TABLES LIKE \"v2_user\"");
      exit($st && $st->fetch()?0:2);
    }catch(Throwable $e){exit(1);}
  ' 2>/dev/null
}

import_schema() {
  # `mysql` (mariadb-client) is the reliable path: install.sql uses SET NAMES /
  # DROP TABLE / CREATE TABLE that artisan cannot run. --skip-ssl avoids the
  # self-signed-cert verification failure inside the compose network.
  if command -v mysql >/dev/null 2>&1; then
    _out=$(mysql --skip-ssl -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
             --default-character-set=utf8mb4 "$DB_DATABASE" < "$ROOT/database/install.sql" 2>&1) && _rc=0 || _rc=$?
    [ -n "$_out" ] && printf '%s\n' "$_out" | sed 's/^/[mysql] /'
    return $_rc
  fi
  log "WARN: mysql client missing — falling back to artisan migrate"
  as_app php artisan migrate --force
}

if [ "${SKIP_DB_INIT:-0}" != "1" ]; then
  if probe_schema; then SCHEMA=0; else SCHEMA=$?; fi

  case "$SCHEMA" in
    0)
      log "database schema present — skipping install.sql"
      if [ "${V2BOARD_AUTO_UPDATE:-0}" = "1" ] && as_app php artisan list 2>/dev/null | grep -q 'v2board:update'; then
        log "V2BOARD_AUTO_UPDATE=1 — running v2board:update"
        artisan v2board:update
      fi
      ;;
    2)
      log "database is empty — importing database/install.sql"
      if import_schema; then
        # Re-probe: the import must actually have created the sentinel table
        if probe_schema; then
          log "install.sql imported"
        else
          log "ERROR: install.sql import left the schema empty — check DB credentials"
        fi
      else
        log "ERROR: install.sql import failed (rc=$?) — check DB credentials/network"
      fi
      if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
        log "seeding admin ($ADMIN_EMAIL)"
        as_app php "$ROOT/.docker/seed-admin.php" 2>&1 | sed 's/^/[seed] /' || log "WARN: admin seeding failed"
      else
        log "ADMIN_EMAIL/ADMIN_PASSWORD unset — skipping admin seed"
      fi
      artisan config:clear
      ;;
    *)
      log "database not reachable at ${DB_HOST}:${DB_PORT} — skipping DB init"
      ;;
  esac
fi

# ── 6. Exec — php-fpm master must stay root; artisan/horizon drop to www-data
log "ready: $*"
case "$1" in
  php-fpm*)
    exec "$@"
    ;;
  php)
    if is_root && command -v su-exec >/dev/null 2>&1; then
      exec su-exec www-data "$@"
    fi
    exec "$@"
    ;;
  *)
    exec "$@"
    ;;
esac
