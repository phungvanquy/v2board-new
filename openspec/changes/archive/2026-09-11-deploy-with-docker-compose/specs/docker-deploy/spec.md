# docker-deploy — delta

## Purpose

Provide a one-command Docker Compose deployment that builds and runs V2Board with all required services (app, database, cache, and queue) so contributors and operators can bring up a working instance without host-level provisioning of PHP, MySQL, or Redis.

## ADDED Requirements

### Requirement: One-command stack startup

The deployment SHALL provide a `docker-compose.yml` (Compose v2) at the repository root that starts the full V2Board stack with a single `docker compose up -d` invocation after configuring environment variables.

#### Scenario: Fresh clone boots successfully

- **WHEN** an operator copies the provided env template to `.env`, sets required secrets, and runs `docker compose up -d --build`
- **THEN** all services become healthy and `GET http://localhost:<mapped-port>/` returns HTTP 200 with the V2Board frontend rendered

#### Scenario: Idempotent restart

- **WHEN** the operator runs `docker compose down && docker compose up -d`
- **THEN** the stack restarts without manual intervention and previously persisted data (DB, Redis, storage) is retained

### Requirement: Service composition

The Compose stack SHALL define at minimum `app` (PHP + web server), `db` (MySQL), `redis`, and `queue` (Horizon) services with explicit dependencies, healthchecks, and restart policies.

#### Scenario: Database is ready before migrations

- **WHEN** `db` is still initializing on first boot
- **THEN** `app` and `queue` wait (via healthcheck + `depends_on: condition: service_healthy` or an entrypoint wait loop) rather than failing migrations

#### Scenario: Queue worker supervision

- **WHEN** Horizon is running and the queue container restarts
- **THEN** Horizon re-launches automatically and processes pending jobs without data loss

### Requirement: Environment wiring

The deployment SHALL wire configuration exclusively through environment variables and a mounted `.env` file; secrets SHALL NOT be baked into the image.

#### Scenario: Env template documents required variables

- **WHEN** an operator reads `.env.docker.example` (or the documented env section)
- **THEN** they find every variable that must be set for `app`/`db`/`redis` to connect (`APP_KEY`, `APP_URL`, `DB_*`, `REDIS_*`, `CACHE_DRIVER`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, etc.) with sane defaults and placeholder values for secrets

#### Scenario: Existing .env is respected

- **WHEN** the operator already has a configured `.env`
- **THEN** the stack uses it without requiring a new file, and Compose does not overwrite it on rebuild

### Requirement: Data persistence and initialization

MySQL and Redis data SHALL be persisted in named volumes. On first boot the database SHALL be initialized from the bundled schema (`database/install.sql`) or via `php artisan v2board:install` as documented; subsequent boots SHALL NOT re-import or wipe data. `storage/` and `public/` writable paths SHALL survive rebuilds.

#### Scenario: First-boot DB initialization

- **WHEN** the `db` volume is empty and the stack starts
- **THEN** MySQL initializes with the schema and seed data expected by V2Board and the app can connect

#### Scenario: Data survives image rebuild

- **WHEN** the operator runs `docker compose build app && docker compose up -d`
- **THEN** existing user/plan/order data remains intact

### Requirement: Production readiness basics

The image SHALL be built from a pinned PHP 8.x base, install system deps and Composer deps at build time (not at runtime), run as a non-root user where practical, set `opcache` appropriately, expose a single HTTP port, and include a `.dockerignore` to avoid copying secrets or build artifacts into the image. Logs SHALL be written to standard output/streams or mounted `storage/logs` so `docker compose logs` surfaces them.

#### Scenario: Build is reproducible and lean

- **WHEN** the operator runs `docker compose build --no-cache`
- **THEN** the build succeeds without downloading Composer at runtime and the resulting image does not contain `.git`, `node_modules`, or host `.env`

#### Scenario: Logs are observable

- **WHEN** the stack is running
- **THEN** `docker compose logs app` and `docker compose logs queue` show Laravel and Horizon output

### Requirement: Documentation and update path

The deployment SHALL document the full operator flow: prerequisites, env setup, `up`/`down`/`logs`/`exec`, first-time install vs. existing-data boot, running `php artisan v2board:update` (or `database/update.sql`) inside the container, and backup/restore of volumes. The bare-metal `init.sh` path SHALL remain functional and unchanged.

#### Scenario: Operator follows README to install

- **WHEN** an operator follows the Docker section in `README`/`docs` on a host with only Docker and Compose installed
- **THEN** they can complete installation without manually installing PHP, MySQL, or Redis on the host

#### Scenario: Update does not require destroying volumes

- **WHEN** a new application version is pulled and the stack is rebuilt
- **THEN** the documented update command runs migrations inside the container without dropping the `db` volume
