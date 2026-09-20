# admin/database-transfer

## Purpose

Enables administrators to export and import the V2Board MySQL database from the admin UI so they can back up, restore, and migrate between servers without shell access.

## ADDED Requirements

### Requirement: Admin can export database to downloadable file

The system SHALL allow an authenticated admin user to export the entire V2Board MySQL database (all tables, schema and data) as a downloadable file via the admin UI/API.

#### Scenario: Successful export download

- **WHEN** an authenticated admin requests a database export
- **THEN** the system streams a complete SQL dump file to the client as a file download with a `Content-Disposition: attachment` header and a filename containing the database name and timestamp

#### Scenario: Export is admin-only

- **WHEN** an unauthenticated or non-admin user requests a database export
- **THEN** the system SHALL reject the request with 401/403 and SHALL NOT produce any dump output

#### Scenario: Export includes all tables

- **WHEN** an admin exports the database
- **THEN** the resulting file SHALL contain the schema and data for every table in the configured V2Board database (no tables silently omitted)

#### Scenario: Export supports compressed download

- **WHEN** the database is large (or the admin requests compressed format)
- **THEN** the system SHALL offer or default to a gzip-compressed `.sql.gz` download, and the file SHALL decompress to a valid SQL dump

### Requirement: Admin can import/restore database from uploaded file

The system SHALL allow an authenticated admin user to restore the database by uploading a previously exported SQL dump file (`.sql` or `.sql.gz`).

#### Scenario: Successful restore from valid dump

- **WHEN** an admin uploads a valid dump produced by the export feature (or a compatible mysqldump of the same schema) and confirms the restore
- **THEN** the system SHALL restore the database to the state captured in the dump and return a success result

#### Scenario: Import requires explicit confirmation

- **WHEN** an admin initiates an import
- **THEN** the system SHALL require an explicit confirmation step (typed confirmation phrase or secondary confirmation dialog) before executing any destructive restore, and SHALL NOT restore on upload alone

#### Scenario: Import is admin-only

- **WHEN** an unauthenticated or non-admin user attempts a database import
- **THEN** the system SHALL reject the request with 401/403 and SHALL NOT modify the database

#### Scenario: Import validates file before executing

- **WHEN** an admin uploads an invalid file (not SQL, truncated, wrong database, or unrecognized format)
- **THEN** the system SHALL reject the file with a descriptive validation error and SHALL NOT alter the database

#### Scenario: Import handles gzip transparently

- **WHEN** an admin uploads a `.sql.gz` file
- **THEN** the system SHALL decompress and restore it without requiring manual decompression

### Requirement: Export and import are audited

The system SHALL create an auditable record for every export and import attempt, including actor identity, timestamp, outcome (success/failure), and for imports the uploaded file name and size.

#### Scenario: Audit entry for export

- **WHEN** an export completes (or fails)
- **THEN** an audit record SHALL be persisted and visible to admins (via log or transfer-history view) showing who triggered it, when, and the result

#### Scenario: Audit entry for import

- **WHEN** an import completes (or fails validation/execution)
- **THEN** an audit record SHALL be persisted showing who triggered it, when, the uploaded file name/size, and the result, and no subsequent export/import SHALL erase prior audit entries

### Requirement: Import is safe and non-destructive on failure

The system SHALL ensure a failed or rejected import does not leave the database in a partially restored state.

#### Scenario: Failed import leaves data intact

- **WHEN** an import fails mid-execution (SQL error, out-of-disk, or invalid statement)
- **THEN** the database SHALL remain recoverable — either the restore is rolled back or the failure is reported with clear guidance and the pre-restore state is not silently corrupted (documented behavior if full transactional rollback is not feasible for MySQL DDL)

#### Scenario: Upload size and rate limits are enforced

- **WHEN** an upload exceeds the configured maximum size or an admin exceeds the import rate limit
- **THEN** the system SHALL reject the request with a clear error without starting a restore

### Requirement: Large database import does not block the admin UI

For large dumps where a synchronous HTTP restore would time out, the system SHALL execute the import asynchronously (queued job) and expose operation status so the admin can track progress without holding the request open.

#### Scenario: Async import with status polling

- **WHEN** an import is dispatched as an async job
- **THEN** the system SHALL return a job/operation identifier immediately, and SHALL provide a status endpoint that reports pending/running/succeeded/failed plus a human-readable message, updating to a terminal state when the job finishes

#### Scenario: Only one restore runs at a time

- **WHEN** a restore is already in progress
- **THEN** a new import request SHALL be rejected with a conflict/busy error until the in-flight restore completes

### Requirement: Pre-restore safety backup

Before executing a destructive import, the system SHALL create or offer an automatic pre-restore backup of the current database so the admin can recover if the imported dump is wrong.

#### Scenario: Automatic pre-restore backup

- **WHEN** an admin confirms an import
- **THEN** the system SHALL first create a timestamped backup of the current database (stored transiently on the server or offered as an immediate download) before applying the uploaded dump, or SHALL explicitly warn and require acknowledgment if automatic backup is disabled/unavailable

### Requirement: Admin UI for database transfer

The admin UI SHALL expose database export and import controls, operation status, and transfer history in a dedicated section under Admin > System (or equivalent), consistent with existing admin navigation and i18n.

#### Scenario: Export and import controls visible to admin

- **WHEN** an admin navigates to the Database / Backup section of the admin panel
- **THEN** they SHALL see an Export button/action, an Import uploader with confirmation, the timestamp/size of the last successful export or backup, and recent transfer history, all labeled with i18n keys

#### Scenario: User feedback for long-running operations

- **WHEN** an export is streaming or an import job is running
- **THEN** the UI SHALL show progress/loading state and SHALL surface success or error messages that match the API result
