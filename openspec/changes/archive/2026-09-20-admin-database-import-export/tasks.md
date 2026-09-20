# Tasks: Admin Database Import/Export

## 1. Backend foundation

- [x] 1.1 Add migration and model for `database_transfer_logs` (fields: user_id, action, file_name, file_size, status, message, timestamps) and verify `php artisan migrate --pretend` shows the table creation
- [x] 1.2 Create `App\Services\DatabaseTransferService` encapsulating mysqldump/mysql orchestration, gzip handling, validation, pre-restore safety backup, and single-restore lock, with unit tests for validation and arg-escaping and verify `php artisan test --filter=DatabaseTransferServiceTest` passes
- [x] 1.3 Create queued job `App\Jobs\DatabaseImportJob` that executes the restore, updates the transfer log/status, cleans up temp files, and handles failure reporting; verify `php artisan test --filter=DatabaseImportJobTest` passes

## 2. API and security

- [x] 2.1 Create `App\Http\Controllers\V1\Admin\DatabaseTransferController` with endpoints `export` (streamed download), `import` (multipart upload with confirmation flag), `status/{id}`, and `history`, all behind `admin` middleware, and verify admin-only access returns 401/403 for unauthenticated/non-admin requests
- [x] 2.2 Register routes in `App\Http\Routes\V1\AdminRoute::map` under the `secure_path` prefix with rate limiting on import and request validation (max size, allowed extensions `.sql`/`.sql.gz`, confirmation required), and verify `php artisan route:list` includes the new routes
- [x] 2.3 Enforce single-restore lock (cache/file lock) and async-vs-sync threshold logic so concurrent or oversized imports are handled correctly; verify via integration test that a second import during an in-flight restore receives 409 and that large uploads return a job id with pollable status

## 3. Export/import robustness

- [x] 3.1 Implement streaming export (chunked `StreamedResponse`, optional on-the-fly gzip, correct `Content-Disposition` filename with db name and timestamp, no full-file buffering) and verify a small DB export downloads and re-imports cleanly
- [x] 3.2 Implement import validation pipeline (extension/MIME, gzip magic, max size, shallow SQL sanity, decompression check) that rejects invalid files without touching the DB and verify with fixture tests for truncated/invalid/wrong-format uploads
- [x] 3.3 Implement pre-restore safety backup to `storage/app/database-backups/pre-restore/` with retention and low-disk handling (block or require explicit ack if backup cannot be created), and verify the safety dump is created before restore and cleaned per retention

## 4. Infrastructure and configuration

- [x] 4.1 Verify `mariadb-client` (`mysqldump`/`mysql`) is already present in `Dockerfile` (Alpine `mariadb-client` at `apk add` line) and create `storage/app/database-backups/{tmp,pre-restore}` as gitignored runtime dirs writable by `www-data`; verify `which mysqldump` succeeds inside the app container (no image change expected)
- [x] 4.2 Add config `config/database-transfer.php` (or `config/v2board.php` entries) for `max_upload_size` (512 MB default via `DB_TRANSFER_MAX_UPLOAD_MB`), `async_threshold` (20-50 MB), `safety_backup_retention` (last 3 dumps) and `rate_limit`, with env overrides documented; verify `php artisan config:clear && php artisan tinker --execute "config('database-transfer.max_upload_size')"` returns expected defaults

## 5. Admin UI — phased (matches design.md decision 8)

- [x] 5.1 Ship minimal in-repo surface (ships with this change): server-rendered Blade page at `/{secure_path}/database` (behind `admin` middleware) with Export button (format choice + last-backup metadata), Import dropzone + typed `RESTORE` confirmation modal, progress/status polling, and history table wired to `DatabaseTransferController` API; verify it renders for admins, redirects/403s for non-admins, and export/import flows work end-to-end
- [ ] 5.2 Follow-up: native "Database Backup" card/route inside the Umi admin SPA via the external admin build + `admin-i18n` pipeline, reusing the stable API from 5.1; track as a separate follow-up change (no file edits in this repo beyond the API contract already shipped)

## 6. Quality and docs

- [x] 6.1 Add feature/integration tests covering export auth, import validation, audit log creation, lock contention, and async status transitions; verify `php artisan test` passes and new tests are included in CI
- [x] 6.2 Update admin guide/README with backup/restore and server-migration procedure (export on old server, import on new, safety backup, rollback steps) and verify docs render correctly
