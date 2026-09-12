# Design: Admin Database Import/Export

## Context

V2Board (Laravel 8, MySQL, Horizon queues, Docker deployment) currently has no in-panel database backup/restore. Operators use `mysqldump`/`mysql` over SSH. The admin panel authenticates via `admin` middleware (`AuthService::decryptAuthData` + `is_admin` check) and routes are registered in `App\Http\Routes\V1\AdminRoute` under the `secure_path` prefix. The app runs in Docker (`docker-compose.yml` + `Dockerfile`), so CLI tool availability is controllable via image build. `SystemController` already owns system-facing admin endpoints (schedule/horizon status), making Admin > System the natural home for backup UI.

See `proposal.md` for motivation; `specs/admin/database-transfer/spec.md` for normative requirements.

## Goals / Non-Goals

**Goals:**
- Self-serve migration: export on server A, import on server B via the admin UI alone.
- Safety: no silent data loss — validation, confirmation, pre-restore backup, audit trail, single-restore lock.
- Usability for large DBs: streaming download, async import with status polling.
- Docker-aware: works on the default `docker-compose.yml` stack without extra manual setup beyond an image rebuild.

**Non-Goals:**
- Selective/table-level export/import, cross-engine (Postgres/SQLite) support, or encrypted-at-rest dumps (follow-up).
- Full disaster-recovery orchestration (object-store replication, scheduled automatic backups) — this change provides manual triggered backup/restore; scheduling can be a follow-up.
- Replacement for existing CLI workflows — those remain valid; this is additive.

## Decisions

### 1. Dump/restore mechanism: `mysqldump`/`mysql` CLI with PHP fallback

- **Decision:** Primary path shells out to `mysqldump` (export) and `mysql` (import) using credentials from `config/database.php` / env. If binaries are absent, fall back to a PHP dumper (e.g., reading via PDO and emitting SQL) or return a clear setup error instructing the operator to rebuild the image.
- **Rationale:** `mysqldump` is the most faithful and performant way to capture MySQL state (triggers, routines, charset, `SET` headers). Pure-PHP dumpers are slower and often miss edge cases.
- **Alternatives considered:** PHP-only (e.g., `ifsnop/mysqldump-php`) — kept as fallback, not primary, because CLI is faster and already expected in server images.

### 2. HTTP surface: dedicated controller

- **Decision:** New `App\Http\Controllers\V1\Admin\DatabaseTransferController` with routes:
  - `GET  /{secure_path}/database/export` — streams dump (or `POST` if we need CSRF body; prefer `GET` with auth header for streaming downloads)
  - `POST /{secure_path}/database/import` — multipart upload (`.sql`, `.sql.gz`)
  - `GET  /{secure_path}/database/transfer/status/{id}` — async job status
  - `GET  /{secure_path}/database/transfer/history` — audit/history list
  - Optionally `POST /{secure_path}/database/transfer/cancel` to cancel a pending job
- **Routes** registered in `AdminRoute::map` under `['middleware' => ['admin','log']]` (same as other admin routes). Rate-limit middleware on import.
- **Alternatives:** Extend `SystemController` — rejected to keep blast radius small and to group transfer-specific logic.

### 3. Streaming vs buffering for export

- **Decision:** Stream `mysqldump` output directly to the HTTP response (`Symfony StreamedResponse` / Laravel `response()->streamDownload`) with `Content-Type: application/octet-stream` and `Content-Disposition: attachment; filename="v2board-{db}-{Ymd-His}.sql[.gz]"`. For on-the-fly gzip, pipe through `gzip` or `gzencode` streaming rather than writing a temp file first.
- **Rationale:** Avoids loading multi-GB dumps into PHP memory and keeps TTFB low.
- **Trade-off:** Streaming makes computing `Content-Length` impossible when gzipping on the fly — clients must handle chunked transfer; acceptable for browser downloads.

### 4. Import validation and execution

- **Decision:** Pre-checks before execution: file extension/MIME, decompression sanity (gzip header/magic), max size (`upload_max_filesize` + app-level cap, e.g., 512 MB default configurable), and a shallow SQL sanity check (non-empty, contains `CREATE TABLE`/`INSERT`). Reject early on failure. On success, optionally create a pre-restore backup (see §6), acquire a distributed lock (cache/file lock) so only one restore runs, then either execute synchronously for small files or dispatch a Horizon job for large files. Restore executes via `mysql < dump` (or piping decompressed stream) scoped strictly to the configured database — credentials never logged, shell args escaped via `escapeshellarg`.
- **Security:** Uploaded file is stored under `storage/app/database-backups/tmp/` with random name, restrictive perms, and deleted after success/failure. No shell interpolation of file contents. SQL is executed only by the `mysql` client against the configured DB; no raw `DB::unprepared` of untrusted content in a way that could escape the DB.

### 5. Async import for large files

- **Decision:** Threshold-based: files above `N` MB (e.g., 50 MB) or uploads that would exceed typical PHP/Nginx timeouts are dispatched to a queued job (`DatabaseImportJob` on the `default` or `database-transfer` queue). The controller returns `{ job_id, status: "pending" }` immediately. Frontend polls `GET .../status/{id}` (or Horizon job status). Small files may run synchronously with the same status record for uniformity.
- **Rationale:** Prevents gateway timeouts and keeps the UI responsive.
- **Alternative:** Always async — also viable; the threshold keeps the simple case simple.

### 6. Pre-restore safety backup

- **Decision:** By default, before applying an import the system creates a timestamped safety dump of the current DB under `storage/app/database-backups/pre-restore/` (same streaming mechanism, written to disk). On success the safety dump is retained for a configurable retention window (e.g., 7 days or last 3). If disk space is insufficient or `mysqldump` is unavailable, the import is blocked unless the admin explicitly acknowledges "proceed without safety backup" via a confirmation flag.
- **Rationale:** Satisfies the spec requirement and protects against wrong-file restores.

### 7. Audit/history store

- **Decision:** Persist transfer records in a small table `database_transfer_logs` (id, user_id, action `export|import`, file_name, file_size, status `success|failed|pending`, message, created_at) — or append to the existing `log`/`audit` mechanism if one exists and fits. Expose via `GET .../history`. Also write to Laravel log for ops visibility.
- **Alternative:** File-only history — rejected because it is harder to query and to show in the UI; a table is cheap and queryable.

### 8. Frontend placement and i18n

- **Constraint (project-specific):** the admin SPA is a **vendored, prebuilt Umi bundle** served from `public/assets/admin/*.js` (loaded by `resources/views/admin.blade.php`); its **source is not in this repo**. The `admin-i18n/` pipeline exists precisely because the admin UI is patched at the compiled-bundle level, not built from source here. Therefore a rich new React/Umi route **cannot be added by editing this repo alone**.
- **Decision — phase the UI:**
  - **In-repo slice (ships with this change):** a minimal, self-contained admin surface for database transfer, living entirely in this repo — the JSON API (decision 2) plus a lightweight server-rendered Blade page at `/{secure_path}/database` (guarded by the same `admin` middleware) that provides Export, Import-with-confirmation, status polling, and history. This is enough to complete a real migration using only the browser, with no external build.
  - **Follow-up slice (out of scope here):** a native "Database Backup" card/route inside the Umi admin SPA under Admin > System, wired via the `admin-i18n` pipeline, once the admin source build is available. The API contract is designed so this later UI needs no backend changes.
- **UI components (either surface):** Export button (with format choice and last-backup metadata), Import dropzone/uploader with a **typed `RESTORE` confirmation** (see Open Questions → now resolved), progress/status indicator, and history table.
- **i18n:** any server-rendered strings use the existing `resources/lang/{en-US,zh-CN}.json` mechanism; API messages go through `__()`. The follow-up SPA card will use `admin.databaseTransfer.*` keys via the `admin-i18n` pipeline.

### 9. Docker and file layout

- **Verified:** `mariadb-client` (which provides `mysqldump`/`mysql`) **is already installed** in the runtime image (`Dockerfile:13` — `apk add ... mariadb-client`). No image change is required for this feature.
- **Decision (remaining):** create `storage/app/database-backups/{tmp,pre-restore}/` at runtime, gitignore them, ensure they are writable by `www-data`, and (optionally) expose the path as a named volume for persistence across restarts. `docker-compose.yml` may expose a volume mount for that path, but is not required to ship the feature.

## Risks / Trade-offs

- **Availability of `mysqldump` in the image** → Mitigation: add to `Dockerfile`; at runtime probe `which mysqldump` and surface a clear error with rebuild instructions if missing; provide PHP fallback for export where feasible.
- **Shell injection via DB credentials/filenames** → Mitigation: never interpolate raw user input into shell; use `escapeshellarg` for every shell token; prefer `MYSQL_PWD` env or option file over `-p<password>` on the command line to avoid `ps` leakage.
- **Large dump causes disk pressure or download failure** → Mitigation: stream, do not stage full file; enforce max size; for safety backups check free disk before writing; document recommended `client_max_body_size` / `upload_max_filesize` values.
- **MySQL DDL is not transactional — failed import can leave partial state** → Mitigation: spec requires "recoverable" rather than strict atomicity; provide the pre-restore safety dump and clear failure messaging with recovery steps; document that point-in-time recovery still requires external binlog backups.
- **Concurrent restores** → Mitigation: distributed lock (cache lock `database:restore:lock` with TTL) + `409 Conflict` on second attempt.
- **Sensitive data exposure (DB dump contains all user data)** → Mitigation: admin-only, audit-logged, rate-limited downloads; filename does not leak secrets; optional secondary password confirmation before export of large/production DBs.
- **Timeouts on synchronous import** → Mitigation: async job threshold + status polling; increase PHP `max_execution_time` for the job worker, not the web process.

## Migration Plan

1. **DB migration**: create `database_transfer_logs` table; no changes to existing tables.
2. **Image**: no `Dockerfile` change required — `mariadb-client` is already present. If the image has been customized to remove it, re-add `mariadb-client` and rebuild.
3. **Storage**: ensure `storage/app/database-backups/{tmp,pre-restore}/` exists and is writable by `www-data`; add to `.gitignore`.
4. **Config**: new `config/database-transfer.php` (or entries in `config/v2board.php`): `max_upload_size`, `async_threshold`, `safety_backup_retention`, `rate_limit`.
5. **Deployment**: deploy code, run `php artisan migrate`, restart Horizon (`php artisan horizon:terminate`) so job class is picked up.
6. **Rollback**: revert code and (if desired) drop `database_transfer_logs`; no data migration to undo. Safety dumps on disk are inert.

## Open Questions — resolved

| Question | Decision | Env/config |
|---|---|---|
| Default `max_upload_size` | 512 MB default; overridable via `DB_TRANSFER_MAX_UPLOAD_MB` | env-only (not an admin settings toggle); enforces `client_max_body_size` / `upload_max_filesize` docs |
| Confirmation on restore | Typed phrase `RESTORE` in confirmation modal (no password re-entry) | matches existing admin patterns; simple against the compiled UI |
| Retention of safety dumps & audit rows | Keep last 3 safety dumps + 7 days of `database_transfer_logs`; GC on next import | pragmatic disk use, no extra cron |

None of these change the specs — `specs/admin/database-transfer/spec.md` requires "explicit confirmation" without prescribing the phrase, so it covers `RESTORE` as-is.

## Follow-up (out of scope for this change)

- Scheduled automatic backups (e.g., daily `mysqldump` to `database-backups/` with object-store sync) — this change provides manual triggered backup/restore; scheduling can build on its service and config.
- Adding the native "Database Backup" card/route inside the Umi admin SPA via the external `admin-i18n` pipeline — API contract is stable; this repo's Blade page bridges the gap until that build is available.
