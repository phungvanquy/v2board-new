<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

# V2Board

V2Board is a proxy-service panel for managing users, subscriptions, payments and proxy nodes (Shadowsocks / V2Ray / Trojan / Hysteria / TUIC / AnyTLS). This fork ships a **one-command Docker deployment** plus an **English admin dashboard** — the upstream panel is Chinese-only.

Supported node backends: [V2bX](https://github.com/wyx2685/V2bX) · [v2node](https://github.com/wyx2685/v2node)

---

## Quick start (Docker — recommended)

Requirements: **Docker Engine 24+** and **Compose v2** (`docker compose version` should print `v2.x`). No PHP / MySQL / Redis needed on the host.

```bash
git clone https://github.com/phungvanquy/v2board-new.git && cd v2board-new
cp .env.docker.example .env          # then edit the secrets inside
docker compose up -d --build         # first boot imports the DB and pre-warms the theme
docker compose ps                    # all 5 services should show (healthy)
```

| What | URL |
|------|-----|
| **Site** | http://localhost:8080/ |
| **Admin login** | see below — it is a secret path, not `/admin` |
| **Health probe** | http://localhost:8080/healthz → `ok` |

### Find your admin login URL (first boot)

There is no fixed `/admin` URL — the panel lives at a secret path (`secure_path`) so bots cannot guess it. On a fresh install it is the CRC32 of your `APP_KEY` (e.g. `http://localhost:8080/a1b2c3d4`).

**Step 1 — print it:**

```bash
docker compose exec app php artisan tinker \
  --execute 'echo "http://localhost:8080/".(config("v2board.secure_path") ?: config("v2board.frontend_admin_path") ?: hash("crc32b", config("app.key")))."\n";'
```

(Replace `localhost:8080` with your domain/`APP_PORT` on a server.)

**Step 2 — open that URL** and sign in with the admin email/password (see "Create the first admin" below).

**Step 3 (optional) — set a memorable path:** once logged in, go to **System config → safe → Admin path** (min 8 chars, letters/digits/`-`/`_` only) → **Save**. The panel and its API move to `/<your-path>` immediately.

### Create the first admin

**Option A — automatic (recommended for new installs).** Put these in `.env` *before* the first `docker compose up`:

```ini
ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=choose-at-least-8-chars
```

The entrypoint creates that admin when `v2_user` is still empty.

**Option B — after the stack is already up:**

```bash
docker compose exec app php artisan tinker
```
```php
$email = 'you@example.com';
$pw = 'choose-at-least-8-chars';
$user = new App\Models\User();
$user->email = $email;
$user->password = password_hash($pw, PASSWORD_DEFAULT);
$user->uuid = App\Utils\Helper::guid(true);
$user->token = App\Utils\Helper::guid();
$user->is_admin = 1;
$user->save();
```

Sign in at the admin URL above with that email + password.

### 5-minute onboarding checklist (after you can log in)

1. **System config** — `Admin → System config` → set **Site name**, **Site URL** (`APP_URL` must be a `http(s)://` URL reachable by nodes), **Subscribe URL / Path**, then **Save**. Verify the save sticks after a refresh (a bug here was fixed in `771db31d` — report it if it regresses).
2. **Payment** — `Admin → Payment config` → enable at least one gateway or users cannot pay. The **Payment docs** are gateway-specific; most need a callback URL pointing at your `APP_URL`.
3. **Plans** — `Admin → Subscriptions` → **Add** a plan (traffic, duration, price). Plans are what users buy; without one the storefront is empty.
4. **Nodes** — `Admin → Nodes` → **Add** a node group, then add nodes under it (grouped by protocol: Shadowsocks / VMess / VLESS / Trojan / Hysteria / TUIC). Each node needs a backing backend (V2bX / v2node) pointed at your panel via `server_api_url` + `server_token` (configured in **System → Server**). See the upstream docs at [v2board.com](https://v2board.com) for node wiring.
5. **Test a user** — create or register a test user, assign a plan, copy its subscription URL and import it into a client (Clash / V2RayN / Shadowrocket / Quantumult X). Traffic should increment in **Users** → the user row.
6. **Backups** — **Advanced Settings → Data Transfer** (or `/<secure_path>/database`) exports a full `.tar.gz` archive of the database and panel/theme settings, and restores backups with typed `RESTORE` confirmation and a pre-restore safety copy. Enable **Telegram full backups** with a dedicated bot token, chat/group ID, and configurable interval to send automatic backups, or use **Back up now**. See [Telegram backup setup and upgrade steps](docs/docker.md#telegram-full-backups). Docker keeps persistent data in named volumes; see **Backup & restore** below for volume backups.
7. **Advanced tools** — the admin sidebar's **Advanced Settings** entry (`/<secure_path>/advanced`) links to **Subscribe Rules** (Russia DIRECT bypass, extra domains; applies on next subscription update), **Happ Encrypted Link** (subscription URL → encrypted `happ://` link, local `crypt4` or remote `crypt5`, with QR code), and **Database** transfer. See [docs/docker.md](docs/docker.md) § Advanced admin tools.

Full operator reference — service layout, environment variables, updates, backups, the optional `webman` profile, live-reload override and troubleshooting: [**docs/docker.md**](docs/docker.md).

---

## Bare-metal deployment (no Docker)

The `init.sh` / `update.sh` / `cli-php.ini` / `pm2.yaml` path is still supported and unchanged:

- Requirements: **PHP 7.3+**, **Composer**, **MySQL 5.5+**, **Redis**, **Laravel**
- `bash init.sh` on a fresh host (interactive — sets `.env`, imports `database/install.sql`; `php artisan v2board:install` prints the admin URL at the end)
- `./update.sh` to pull and run `v2board:update`
- Bare metal uses the same secret admin path — print it with `php artisan tinker --execute 'echo "/".(config("v2board.secure_path") ?: config("v2board.frontend_admin_path") ?: hash("crc32b", config("app.key")))."\n";'`
- See `./init.sh` and the upstream guide at [v2board.com](https://v2board.com)

### Migrating an existing panel to this fork

```bash
git remote set-url origin https://github.com/phungvanquy/v2board-new.git
git checkout master
./update.sh
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
# then in the admin: Theme config → pick `default` → Theme settings → Save
```

---

## Contributing (quality gates)

Before opening a pull request, run the checks locally. CI enforces the same set on every PR to `master`/`main`.

```bash
# One-time setup
composer install                 # installs php-cs-fixer, phpstan, phpunit
npm install                      # installs eslint, prettier (for the small JS surface under resources/js)

# Check
composer lint:check              # php-cs-fixer (PSR-12 + Laravel-ish rules)
composer analyse                 # phpstan level 5 with checked-in baseline
npm run lint                     # eslint resources/js
bash tools/abort-ratchet.sh check # no new abort( ) calls — use App\Exceptions\ApiException

# Fix (auto-correctable)
composer cs:fix
npm run lint:fix

# Tests
composer test                  # full suite (Unit + Feature)
composer test:unit             # unit suite only (no DB, fast)
composer test:feature          # feature suite only
composer test:coverage         # text + HTML/clover report into storage/coverage

# CI also runs phpunit directly; if you have no local PHP use the image:
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit
```

**Notes**

- PHP 8.2 is the pinned platform (`composer.json → config.platform.php`). Do not use newer PHP to generate `composer.lock`; 8.3+ breaks the runtime image.
- Adding a new PHP file: run `composer cs:fix` before commit. The fixer will format the file.
- Adding a new `abort(...)` call in `app/` will fail CI. Throw `ApiException::fail(...)` / `::badRequest(...)` / `::forbidden(...)` instead. When you migrate a legacy file off `abort()`, run `bash tools/abort-ratchet.sh ratchet` to lower the ceiling for everyone.
- PHPStan uses a checked-in baseline (`phpstan-baseline.neon`) to grandfather legacy errors. Do not regenerate the baseline casually — new errors should be fixed, not baselined.
- Tests: put pure/stateless coverage under `tests/Unit` (no DB/network) and HTTP or DB-backed flows under `tests/Feature`. Use the builders in `tests/Support/ModelBuilder.php` to create deterministic `User`/`Plan`/`Order` fixtures. A coverage driver (Xdebug or PCOV) must be enabled in your PHP for `composer test:coverage`; without it PHPUnit prints a "no code coverage driver" warning but still runs the suite.

---

## Common operations (Docker)

```bash
docker compose logs -f                  # all services
docker compose logs -f app horizon      # just PHP + queue
docker compose exec app php artisan horizon:status
docker compose exec app php artisan tinker
docker compose exec db mysql -uv2board -p"$DB_PASSWORD" v2board
docker compose restart horizon          # after queue issues
docker compose down                     # stop, keep volumes
docker compose down -v                  # stop and wipe all data!
```

Apply a panel update without losing data:

```bash
git pull
docker compose build app nginx          # preserve db_data
docker compose up -d
docker compose exec app php artisan v2board:update
```

Back up / restore a volume (example: `db_data`):

```bash
docker volume ls | grep v2board
mkdir -p backups
docker run --rm -v v2board_db_data:/data -v "$PWD/backups:/backup" alpine \
  tar czf /backup/db_data-$(date +%F).tgz -C /data .
# restore (stop first)
docker compose down
docker run --rm -v v2board_db_data:/data -v "$PWD/backups:/backup" alpine \
  sh -c 'rm -rf /data/* && tar xzf /backup/db_data-YYYY-MM-DD.tgz -C /data'
docker compose up -d
```

Live code reload for development (no rebuild on every edit):

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
docker compose up -d   # merges the override automatically
```

---

## This fork vs upstream

- **Admin in English.** The panel's admin UI was rewritten from Chinese to English at the bundle level (`admin-i18n/` pipeline, locale table, `__()` PHP keys, guard/shape checks). No API or theme changes.
- **Advanced admin tools.** The admin sidebar has an **Advanced Settings** hub (`/<secure_path>/advanced`) with out-of-panel pages: **Subscribe Rules** (Russia DIRECT / VPN-bypass rules injected into every subscription format, with admin-managed extra domains), **Happ Encrypted Link** converter (plain subscription URL → encrypted `happ://` deep link via local RSA-4096 `crypt4` or remote `crypt5`, with email lookup + QR code), and **Database Transfer** below.
- **Docker-first.** The `Dockerfile` + `docker-compose.yml` + `.env.docker.example` path above does not exist upstream (their `.gitignore` hid `docker-compose.yml`). Host provisioning is no longer required.
- Everything else (theme, user-facing site, node protocols, payments) tracks upstream `wyx2685/v2board`.

---

## Demo / upstream docs

- Demo user: <https://v2bdemo.v-50.me/>  —  Demo admin: <https://v2bdemo.v-50.me/admindashboard> (any email/password)
- Upstream docs: <https://v2board.com>

## Sponsors

Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community

Telegram: [@unofficialV2board](https://t.me/unofficialV2board)

## How to Feedback

Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
