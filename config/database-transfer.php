<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Transfer (admin backup / restore)
    |--------------------------------------------------------------------------
    |
    | Values are env-overridable; the defaults fit the project's Docker Compose
    | stack. Remember that PHP's upload_max_filesize/post_max_size and nginx's
    | client_max_body_size cap what can actually reach this endpoint — see
    | docs/docker.md ("Database backup & restore") for the matching settings.
    |
    */

    // Maximum upload size for database import (bytes). Env is in MB for ergonomics.
    'max_upload_size' => ((int) env('DB_TRANSFER_MAX_UPLOAD_MB', 512)) * 1024 * 1024,

    // Above this size the import is dispatched to a queue worker instead of
    // running inside the HTTP request (which would hit PHP/FPM timeouts).
    'async_threshold' => ((int) env('DB_TRANSFER_ASYNC_THRESHOLD_MB', 20)) * 1024 * 1024,

    // How many timestamped pre-restore safety dumps to keep on disk.
    'safety_backup_retention' => (int) env('DB_TRANSFER_SAFETY_RETENTION', 3),

    // Audit rows older than this are garbage-collected on the next import.
    // Set to 0 to keep every row forever.
    'audit_retention_days' => (int) env('DB_TRANSFER_AUDIT_RETENTION_DAYS', 7),

    // Refuse to write a safety dump when the filesystem holds less than this
    // much free space (the restore itself is then blocked unless acknowledged).
    'min_free_space_mb' => (int) env('DB_TRANSFER_MIN_FREE_MB', 100),

    // Import attempts per admin per window. The window is a whole minute count,
    // so "5 per 10" means five imports per ten minutes.
    'rate_limit_max_attempts' => (int) env('DB_TRANSFER_RATE_LIMIT_ATTEMPTS', 5),
    'rate_limit_decay_minutes' => (int) env('DB_TRANSFER_RATE_LIMIT_DECAY_MINUTES', 10),

    // Lock TTL for single-restore mutual exclusion (seconds). Also the ceiling on
    // how long a crashed worker can keep the restore slot busy.
    'restore_lock_ttl' => (int) env('DB_TRANSFER_RESTORE_LOCK_TTL', 3600),

    // Seconds the async restore is allowed to run inside the worker.
    'job_timeout' => (int) env('DB_TRANSFER_JOB_TIMEOUT', 3600),

    // Queue the async restore is pushed to. Must be a queue the deployment's
    // worker actually watches (see config/horizon.php 'environments').
    'queue' => env('DB_TRANSFER_QUEUE', 'stat'),

    // Where transient files live (relative to storage/app).
    'tmp_dir' => env('DB_TRANSFER_TMP_DIR', 'database-backups/tmp'),
    'safety_dir' => env('DB_TRANSFER_SAFETY_DIR', 'database-backups/pre-restore'),

    // Retained export copies admins can re-download from History (relative to storage/app).
    'export_dir' => env('DB_TRANSFER_EXPORT_DIR', 'database-backups/exports'),

    // Keep a server-side copy of each export so History offers a Download action.
    'keep_exports' => filter_var(env('DB_TRANSFER_KEEP_EXPORTS', true), FILTER_VALIDATE_BOOLEAN),

    // How many retained export copies to keep before the oldest are pruned (0 = keep all).
    'export_retention' => (int) env('DB_TRANSFER_EXPORT_RETENTION', 5),

    // Take a safety dump before each restore when possible.
    'safety_backup_enabled' => filter_var(env('DB_TRANSFER_SAFETY_BACKUP', true), FILTER_VALIDATE_BOOLEAN),
];
