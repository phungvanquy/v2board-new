# Proposal: Admin Database Import/Export

## Why

Operators need to migrate V2Board to a new server, create off-site backups, or restore after data loss — today the only option is SSH/mysqldump which is unavailable to non-technical admins and requires shell access that hosted environments may not expose. An in-panel database import/export removes that dependency and makes server migration a first-class, self-serve workflow.

## What Changes

- Add admin-only **Export Database** action that produces a downloadable SQL dump (full schema + data) of the V2Board MySQL database, streamed to the browser without buffering the entire dump in PHP memory.
- Add admin-only **Import / Restore Database** action that accepts an uploaded `.sql` / `.sql.gz` file, validates it, and restores it into the configured database with explicit confirmation and pre-restore safety prompt.
- Record an audit log entry for every export and import invocation (who, when, outcome, and for imports the uploaded file name/size).
- Gate both actions behind the existing `admin` middleware (same guard as Config/Plan/Server management) and add a dedicated permission/rate-limit to prevent abuse or accidental repeated restores.
- For large databases, run import as a queued job with progress/status polling so the HTTP request does not time out.
- Frontend: add Database Backup section under Admin > System (alongside queue/schedule status), with Export button, Import uploader, last-backup metadata, and operation history.

## Capabilities

### New Capabilities
- `admin/database-transfer`: Admin-facing database export (dump + download) and import (upload + restore) that enables migration and backup/restore without shell access.

### Modified Capabilities
- None — existing specs (`admin-dashboard-i18n`, `docker-deploy`, `code-quality/tooling`, `testing/coverage`) are orthogonal; this is additive.

## Impact

- **Backend**: New controller (`V1\Admin\DatabaseTransferController` or `SystemController` extension), service class for dump/restore orchestration, new admin routes under the secured `secure_path` prefix, optional queued job for import, audit logging.
- **Database**: No schema migration required for core behavior; optional small table or config entry to track last backup time/size and transfer history (if file-based history is preferred, no migration).
- **Frontend (admin SPA)**: New panel/section in System or Settings area; API calls to the new endpoints; i18n keys.
- **Infrastructure**: Depends on `mysqldump`/`mysql` CLI being available in the app container (add to Dockerfile if missing) or falls back to a PHP-based dumper; `storage/app/database-backups/` for transient staging is excluded from version control. Docker image and `docker-compose.yml` may need a `mysqldump` package ensure.
- **Security**: Sensitive operation — must enforce admin auth, CSRF/permission check, optional secondary confirmation (password re-entry or typed confirmation phrase), rate limiting, max upload size, and safe handling of uploaded SQL (no execution outside the target DB, no shell injection via arguments).
- **Docs**: Update README / admin guide with backup/restore and migration procedure.
