<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DatabaseTransferLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DatabaseTransferService
{
    public const LOCK_KEY = 'database_transfer_restore_lock';
    public const CONFIRM_PHRASE = 'RESTORE';
    // Minimum free bytes required on the safety-backup filesystem before dumping.
    public const MIN_FREE_BYTES = 104857600;
    // Cap on the message column so a verbose mysqldump stderr cannot blow up the audit row.
    public const MAX_MESSAGE_LENGTH = 1000;
    // The audit table must never be captured with its data, and its structure must be
    // restored idempotently — otherwise a restore wipes its own history and reverts
    // earlier rows (violates "no import/export SHALL erase prior audit entries").
    public const AUDIT_TABLE = 'v2_database_transfer_log';

    // Upload kinds, decided by validateUpload() from the extension + magic bytes.
    public const KIND_SQL = 'sql';
    public const KIND_SQL_GZ = 'sqlgz';
    public const KIND_BUNDLE = 'bundle';

    /**
     * True when $name ends with $suffix. PHP 7.3-safe (no str_ends_with).
     */
    private function endsWith(string $name, string $suffix): bool
    {
        return substr($name, -strlen($suffix)) === $suffix;
    }

    /**
     * Runtime dirs must exist and be writable; Flysystem creates the upload path
     * but the safety dump / prune paths are used directly.
     */
    public function ensureDirs(): void
    {
        foreach ([
            (string) config('database-transfer.tmp_dir'),
            (string) config('database-transfer.safety_dir'),
            (string) config('database-transfer.export_dir'),
        ] as $dir) {
            $absolute = Storage::path($dir);
            if (!is_dir($absolute)) {
                @mkdir($absolute, 0755, true);
            }
        }
    }

    /**
     * Free bytes on the filesystem holding a storage-relative dir, or null if unknown.
     */
    public function freeSpaceAt(string $relativeDir): ?int
    {
        $dir = Storage::path($relativeDir);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }

        $free = @disk_free_space($dir);

        return $free === false ? null : (int) $free;
    }

    /**
     * Validate an uploaded import file. Throws ApiException on failure.
     *
     * The full bundle (tar.gz with a manifest) reuses the same extension
     * detection + gzip-magic check the single-dump does, so early rejection
     * (wrong type, oversized, truncated) happens before any byte lands in
     * the database or the config dir.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array{kind: string, is_gz: bool, size: int, original_name: string}
     */
    public function validateUpload($file): array
    {
        $originalName = $file->getClientOriginalName();
        $size = (int) $file->getSize();
        $lowerName = strtolower($originalName);

        if ($this->endsWith($lowerName, '.tar.gz') || $this->endsWith($lowerName, '.tgz')) {
            $kind = self::KIND_BUNDLE;
            $isGz = true;
        } elseif ($this->endsWith($lowerName, '.sql.gz')) {
            $kind = self::KIND_SQL_GZ;
            $isGz = true;
        } elseif ($this->endsWith($lowerName, '.sql')) {
            $kind = self::KIND_SQL;
            $isGz = false;
        } else {
            throw ApiException::badRequest(__('Invalid file type. Only .sql, .sql.gz, and .tar.gz (full backup) are allowed.'));
        }

        $maxSize = (int) config('database-transfer.max_upload_size');
        if ($size > $maxSize) {
            $maxMb = (int) round($maxSize / 1024 / 1024);
            throw ApiException::badRequest(__('File too large. Maximum allowed size is :size MB.', ['size' => $maxMb]));
        }

        if ($size === 0) {
            throw ApiException::badRequest(__('Uploaded file is empty.'));
        }

        return [
            'kind' => $kind,
            'is_gz' => $isGz,
            'size' => $size,
            'original_name' => $originalName,
        ];
    }

    /**
     * Shallow SQL sanity check — read the first ~64KB (decompressing gzip on the
     * fly) and require an SQL marker. Throws on obviously invalid content.
     */
    public function assertLooksLikeSql(string $path, bool $isGz): void
    {
        if ($isGz) {
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                throw ApiException::badRequest(__('Unable to read uploaded file.'));
            }
            $magic = fread($fh, 2);
            fclose($fh);
            if ($magic !== "\x1f\x8b") {
                throw ApiException::badRequest(__('File does not appear to be valid gzip.'));
            }
            $head = @file_get_contents('compress.zlib://' . $path, false, null, 0, 65536);
            if ($head === false || $head === '') {
                throw ApiException::badRequest(__('Unable to decompress gzip file or file is empty.'));
            }
        } else {
            $head = @file_get_contents($path, false, null, 0, 65536);
            if ($head === false || trim($head) === '') {
                throw ApiException::badRequest(__('Uploaded file is empty or unreadable.'));
            }
        }

        if (!preg_match('/\b(CREATE\s+TABLE|INSERT\s+INTO|DROP\s+TABLE|SET\s+NAMES|ENGINE\s*=)\b/i', $head)) {
            throw ApiException::badRequest(__('File does not look like a SQL dump.'));
        }
    }

    /**
     * Full-decompression integrity check for gzip uploads. A truncated stream still
     * yields valid SQL in its first bytes, so the head check above is not enough —
     * `gzip -t` catches the trailing CRC/size mismatch without holding the dump in memory.
     */
    public function assertGzIntegrity(string $path): void
    {
        $output = [];
        $code = null;
        @exec('gzip -t ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw ApiException::badRequest(__('Gzip file is truncated or corrupted: :msg', [
                'msg' => trim(substr(implode("\n", $output), 0, 200)),
            ]));
        }
    }

    /**
     * Persist an uploaded file to the tmp dir and return its absolute path.
     *
     * @param \Illuminate\Http\UploadedFile $file
     */
    public function storeUpload($file, string $kind): string
    {
        $this->ensureDirs();
        $tmpDir = (string) config('database-transfer.tmp_dir');
        $ext = $kind === self::KIND_SQL_GZ ? 'sql.gz' : ($kind === self::KIND_BUNDLE ? 'tar.gz' : 'sql');
        $prefix = $kind === self::KIND_BUNDLE ? 'import_bundle_' : 'import_';
        $name = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        try {
            $stored = Storage::putFileAs($tmpDir, $file, $name);
        } catch (\Throwable $e) {
            Log::warning('[database-transfer] store upload failed', ['error' => $e->getMessage()]);
            throw ApiException::fail(__('Failed to store uploaded file.'));
        }
        // putFileAs() returns the stored path or false; false means the disk rejected the write.
        if (!$stored) {
            throw ApiException::fail(__('Failed to store uploaded file.'));
        }

        $absolute = Storage::path($tmpDir . '/' . $name);
        // Do not leave an operator-readable dump lying around world-readable.
        @chmod($absolute, 0600);

        return $absolute;
    }

    /**
     * Connection tokens shared by mysqldump and mysql.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function connection(): array
    {
        $conn = (array) config('database.connections.mysql');

        return [
            'host' => (string) ($conn['host'] ?? '127.0.0.1'),
            'port' => (string) ($conn['port'] ?? '3306'),
            'database' => (string) ($conn['database'] ?? ''),
            'username' => (string) ($conn['username'] ?? ''),
            'password' => (string) ($conn['password'] ?? ''),
        ];
    }

    /**
     * MYSQL_PWD keeps the DB password out of the process arguments (it would be
     * visible in `ps` on the command line). It is passed as a real environment
     * variable to the child process, never interpolated into the command string.
     *
     * @param  array{password: string} $conn
     * @return array<string, string>
     */
    private function env(array $conn): array
    {
        return $conn['password'] === '' ? [] : ['MYSQL_PWD' => $conn['password']];
    }

    /**
     * Run a shell command through sh -c with an explicit environment, returning
     * [exitCode, stdout, stderr]. Used instead of exec() so the DB password can
     * travel in the child environment rather than the command line.
     *
     * @param  array<string, string> $env
     * @return array{code: int, out: string, err: string}
     */
    public function run(string $cmd, array $env = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            'sh -c ' . escapeshellarg($cmd),
            $descriptors,
            $pipes,
            base_path(),
            array_merge(getenv() ?: [], $env),
            ['suppress_errors' => true]
        );

        if (!is_resource($process)) {
            return ['code' => -1, 'out' => '', 'err' => __('Unable to start the database client process.')];
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /**
     * Build a mysqldump command. Every token is escapeshellarg()d; the password never
     * appears in the command string.
     *
     * The export always appends `--ignore-table=<db>.<audit_table>` so the audit table's
     * *data* never leaves the server in a dump — but createSafetyBackup() then appends
     * the table's *structure* (`--no-data` into the same stream) so a restored server
     * still has a valid, empty-and-appendable history table instead of a missing one.
     *
     * @return array{cmd: string, env: array<string, string>}
     */
    public function buildDumpCommand(?string $outputPath = null): array
    {
        $conn = $this->connection();
        // An operator-chosen DB name is a shell token and a mysqldump argument;
        // filter to identifier-safe characters (no spaces, semicolons, quotes).
        $dbIdent = preg_replace('/[^A-Za-z0-9_$-]/', '', $conn['database']) ?: $conn['database'];

        $parts = [
            'mysqldump',
            // utf8mb4 matches the schema charset so a dump re-imports byte-identically.
            '--default-character-set=utf8mb4',
            // Consistent dump flags — kept compatible with the MariaDB client in the image:
            // --no-tablespaces avoids the PROCESS privilege requirement on stock installs.
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--routines',
            '--events',
            '--ignore-table=' . escapeshellarg($dbIdent . '.' . self::AUDIT_TABLE),
            '-h' . escapeshellarg($conn['host']),
            '-P' . escapeshellarg($conn['port']),
            '-u' . escapeshellarg($conn['username']),
            escapeshellarg($conn['database']),
        ];

        $cmd = implode(' ', $parts);
        if ($outputPath !== null) {
            $cmd .= ' > ' . escapeshellarg($outputPath);
        }

        return ['cmd' => $cmd, 'env' => $this->env($conn)];
    }

    /**
     * The audit table's structure as a CREATE TABLE IF NOT EXISTS statement, rendered
     * from the live schema so migrations and future columns stay in sync. Restoring it
     * with IF NOT EXISTS (and no DROP/Data) is idempotent: a server that already has
     * history keeps it; a server that lacks the table gains an empty one.
     *
     * @return array{ddl: string|null, error: string|null}
     */
    public function auditTableDdl(): array
    {
        try {
            $row = \Illuminate\Support\Facades\DB::selectOne('SHOW CREATE TABLE `' . self::AUDIT_TABLE . '`');
            $ddl = is_object($row) ? ((array) $row)['Create Table'] ?? null : null;
            if (!is_string($ddl) || trim($ddl) === '') {
                return ['ddl' => null, 'error' => 'audit table schema is unreadable'];
            }
            // Strip AUTO_INCREMENT=... (restore as a fresh sequence) and promote the
            // statement to idempotent so it never drops or truncates existing history.
            $ddl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl);
            $ddl = preg_replace('/^CREATE TABLE\s+`/i', 'CREATE TABLE IF NOT EXISTS `', $ddl);
            $ddl .= ';';

            return ['ddl' => $ddl, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('[database-transfer] could not read audit table schema', ['error' => $e->getMessage()]);

            return ['ddl' => null, 'error' => 'could not read audit table schema'];
        }
    }

    /**
     * Build a mysql restore command for a dump file (plain .sql, or .sql.gz piped
     * through gzip). Every token is escapeshellarg()d.
     *
     * @return array{cmd: string, env: array<string, string>}
     */
    public function buildImportCommand(string $dumpPath, bool $isGz): array
    {
        $conn = $this->connection();

        $mysql = 'mysql'
            . ' --default-character-set=utf8mb4'
            . ' -h' . escapeshellarg($conn['host'])
            . ' -P' . escapeshellarg($conn['port'])
            . ' -u' . escapeshellarg($conn['username'])
            . ' ' . escapeshellarg($conn['database']);

        // gzip -dc exits non-zero on a truncated stream; sh's default pipeline
        // status is the last command's, so a corrupted dump still fails loudly here.
        $cmd = $isGz
            ? 'gzip -dc ' . escapeshellarg($dumpPath) . ' | ' . $mysql
            : $mysql . ' < ' . escapeshellarg($dumpPath);

        return ['cmd' => $cmd, 'env' => $this->env($conn)];
    }

    /**
     * Shell command that streams a dump to stdout (gzip-compressed when asked).
     * Used by the streaming export; stderr stays on fd 2 so it cannot corrupt the file.
     *
     * $appendSql is an optional idempotent SQL block (the audit table's CREATE-only DDL)
     * emitted after the dump so every exported format — not just the full bundle — keeps
     * "all tables present" without shipping the audit table's *data* (which a restore
     * would otherwise replay and erase transfer history).
     */
    public function buildExportCommand(bool $gz, ?string $appendSql = null): string
    {
        $dump = $this->buildDumpCommand(null)['cmd'];

        if ($appendSql !== null && trim($appendSql) !== '') {
            // Group so both the dump and the appended DDL flow through one pipe to gzip.
            $dump = '{ ' . $dump . '; printf %s ' . escapeshellarg("\n" . $appendSql . "\n") . '; }';
        }

        return $gz ? $dump . ' | gzip' : $dump;
    }

    /**
     * Idempotent CREATE-only block appended to every exported dump. The dump itself
     * carries no audit *data* (--ignore-table); this block ensures the *structure*
     * is present either way, so a migrated server always has a valid history table.
     * Null when the schema is unreadable (exports still succeed — they lose history
     * the same way they did before this feature, and a warning is logged).
     */
    public function auditStructureBlock(): ?string
    {
        $ddl = $this->auditTableDdl();
        if ($ddl['ddl'] === null) {
            Log::warning('[database-transfer] export lacks audit structure', ['reason' => $ddl['error']]);

            return null;
        }

        return "-- The audit history table is restored by structure only; its data\n"
            . "-- never travels in a backup, so restores cannot erase transfer history.\n"
            . $ddl['ddl'];
    }

    /**
     * Absolute path of a config file the full backup carries, or null when absent.
     */
    private function configFilePath(string $relative): ?string
    {
        $abs = base_path() . '/' . ltrim($relative, '/');

        return is_file($abs) ? $abs : null;
    }

    /**
     * The admin-UI config surface that is NOT in the database: the System config file
     * plus every theme file. These live under config/ (a named volume in Docker), so a
     * DB-only dump loses them on migration — the full bundle carries them.
     *
     * @return array<string, string> bundle-relative path => absolute source path
     */
    public function configFiles(): array
    {
        $files = [];

        $v2board = $this->configFilePath('config/v2board.php');
        if ($v2board !== null) {
            $files['config/v2board.php'] = $v2board;
        }

        $themes = glob(base_path() . '/config/theme/*.php') ?: [];
        foreach ($themes as $theme) {
            $files['config/theme/' . basename($theme)] = $theme;
        }

        return $files;
    }

    /**
     * Build a full backup (dump.sql + config files + manifest) as a single tar.gz on
     * disk, then stream that file to the browser. GNU tar is available in the runtime
     * image (busybox tar lacks streaming -T -), so the bundle is staged, not streamed.
     *
     * The staging dir lives under the tmp backup dir and is always removed in finally.
     *
     * @return array{path: string, size: int} absolute path to the finished .tar.gz
     * @throws ApiException when mysqldump or tar fails
     */
    public function buildFullBundle(): array
    {
        if (!$this->isCliAvailable()) {
            throw ApiException::fail(__('Database export is unavailable: mysqldump is not installed on this server. Rebuild the app image.'));
        }

        $this->ensureDirs();
        $tmpDir = (string) config('database-transfer.tmp_dir');
        $stage = Storage::path($tmpDir . '/bundle_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)));
        if (!@mkdir($stage . '/config/theme', 0700, true)) {
            throw ApiException::fail(__('Could not prepare a staging directory for the export.'));
        }

        try {
            $dumpPath = $stage . '/dump.sql';
            $dump = $this->buildDumpCommand($dumpPath);
            $result = $this->run($dump['cmd'], $dump['env']);
            if ($result['code'] !== 0 || !is_file($dumpPath) || filesize($dumpPath) === 0) {
                $combined = trim($result['out'] . "\n" . $result['err']);
                throw ApiException::fail($this->redact(
                    $combined !== '' ? $combined : __('mysqldump exited with code :code.', ['code' => (string) $result['code']]),
                    (string) ($dump['env']['MYSQL_PWD'] ?? '')
                ) ?: __('mysqldump produced no dump.'));
            }

            // Append the audit table's *structure only* (see auditStructureBlock()) to the
            // same dump.sql, so a migrated server still owns a valid history table —
            // while its pre-restore content can never be wiped by the restore replay.
            $block = $this->auditStructureBlock();
            if ($block !== null) {
                file_put_contents($dumpPath, "\n" . $block . "\n", FILE_APPEND);
            }

            $copied = [];
            foreach ($this->configFiles() as $rel => $abs) {
                if (@copy($abs, $stage . '/' . $rel)) {
                    $copied[] = $rel;
                } else {
                    Log::warning('[database-transfer] could not copy config into bundle', ['file' => $rel]);
                }
            }

            $manifest = [
                'kind' => 'v2board-full-backup',
                'version' => 1,
                'db' => (string) config('database.connections.mysql.database', ''),
                'created_at' => time(),
                'dump' => 'dump.sql',
                'config' => $copied,
            ];
            if (file_put_contents($stage . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES)) === false) {
                throw ApiException::fail(__('Could not write the bundle manifest.'));
            }

            $tarPath = Storage::path($tmpDir . '/export_bundle_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.tar.gz');
            $tar = $this->run('tar -czf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($stage) . ' .');
            if ($tar['code'] !== 0 || !is_file($tarPath) || filesize($tarPath) === 0) {
                @unlink($tarPath);
                throw ApiException::fail(__('Failed to assemble the full backup archive.'));
            }
            @chmod($tarPath, 0600);

            return ['path' => $tarPath, 'size' => (int) filesize($tarPath)];
        } finally {
            $this->removeDir($stage);
        }
    }

    /**
     * Recursively delete a staging directory created by the bundle builder.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_merge(glob($dir . '/*') ?: [], glob($dir . '/.*') ?: []);
        foreach ($items as $item) {
            if (basename($item) === '.' || basename($item) === '..') {
                continue;
            }
            is_dir($item) ? $this->removeDir($item) : @unlink($item);
        }
        @rmdir($dir);
    }

    /**
     * Are mysqldump/mysql usable here? Probed once per request at most.
     */
    public function isCliAvailable(): bool
    {
        $out = [];
        $code = null;
        @exec('command -v mysqldump 2>/dev/null', $out, $code);

        return $code === 0;
    }

    /**
     * Create a timestamped pre-restore safety dump of the current database.
     *
     * @return array{created: bool, path: string|null, reason: string|null}
     */
    public function createSafetyBackup(): array
    {
        $refuse = function (string $reason): array {
            Log::warning('[database-transfer] safety backup not created', ['reason' => $reason]);

            return ['created' => false, 'path' => null, 'reason' => $reason];
        };

        if (!config('database-transfer.safety_backup_enabled')) {
            return $refuse(__('Safety backup is disabled on this server.'));
        }

        if (!$this->isCliAvailable()) {
            return $refuse(__('mysqldump is not available in this image.'));
        }

        $this->ensureDirs();
        $safetyDir = (string) config('database-transfer.safety_dir');
        $relative = $safetyDir . '/pre_restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql.gz';
        $absolute = Storage::path($relative);

        $free = $this->freeSpaceAt($safetyDir);
        if ($free !== null && $free < self::MIN_FREE_BYTES) {
            return $refuse(__('Not enough disk space for a safety backup (:free MB free).', [
                'free' => (int) round($free / 1048576),
            ]));
        }

        $dump = $this->buildDumpCommand(null);
        $cmd = $dump['cmd'] . ' | gzip > ' . escapeshellarg($absolute);

        $result = $this->run($cmd, $dump['env']);
        $combined = trim($result['out'] . "\n" . $result['err']);

        if ($result['code'] !== 0 || !file_exists($absolute) || filesize($absolute) === 0) {
            @unlink($absolute);

            return $refuse($this->redact(
                $combined !== '' ? $combined : __('mysqldump exited with code :code.', ['code' => (string) $result['code']]),
                (string) ($dump['env']['MYSQL_PWD'] ?? '')
            ));
        }

        // Same caveat as the full bundle: the dump stream holds every business table,
        // but the audit data is appended as *structure only* afterwards, so a safety
        // restore can never rewind the transfer history.
        $auditDdl = $this->auditTableDdl();
        if ($auditDdl['ddl'] !== null) {
            // The file on disk is gzipped; use a streaming append rather than loading it.
            $append = $this->run(
                'printf %s ' . escapeshellarg(
                    "\n-- The audit history table is restored by structure only; its data\n-- never travels in a backup, so restores cannot erase transfer history.\n"
                    . $auditDdl['ddl'] . "\n"
                ) . ' | gzip >> ' . escapeshellarg($absolute)
            );
            if ($append['code'] !== 0) {
                @unlink($absolute);

                return $refuse(__('mysqldump succeeded but the safety dump could not be finalized.'));
            }
        } else {
            Log::warning('[database-transfer] safety backup lacks audit structure', ['reason' => $auditDdl['error']]);
        }

        @chmod($absolute, 0600);
        $this->pruneSafetyBackups();

        return ['created' => true, 'path' => $relative, 'reason' => null];
    }

    /**
     * Decide whether a restore may proceed, per the safety-backup requirement: a dump is
     * taken first; when it cannot be taken the admin must acknowledge it explicitly
     * ($skipSafety), otherwise the restore is refused.
     *
     * @return array{created: bool, path: string|null}
     * @throws ApiException when a required safety backup is impossible and unacknowledged
     */
    public function ensureSafetyBackup(bool $skipSafety): array
    {
        if ($skipSafety) {
            Log::warning('[database-transfer] restore proceeding without a safety backup (operator acknowledged)');

            return ['created' => false, 'path' => null];
        }

        $result = $this->createSafetyBackup();
        if (!$result['created']) {
            throw ApiException::badRequest(
                __('Safety backup failed: :reason Re-submit with "skip_safety_backup" to restore without it.', [
                    'reason' => (string) $result['reason'],
                ])
            );
        }

        return $result;
    }

    /**
     * Keep only the N most recent safety dumps. Rows whose retained file was pruned
     * (safety dump or export copy) have their stored_path cleared in the same sweep
     * so History never offers a Download for a missing file.
     */
    public function pruneSafetyBackups(): void
    {
        $retention = (int) config('database-transfer.safety_backup_retention', 3);
        $safetyDir = (string) config('database-transfer.safety_dir');
        $absoluteDir = Storage::path($safetyDir);

        if (!is_dir($absoluteDir)) {
            return;
        }

        $files = glob($absoluteDir . '/*.sql.gz');
        if ($files === false || count($files) <= $retention) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $retention) as $old) {
            @unlink($old);
            try {
                DatabaseTransferLog::where('stored_path', $safetyDir . '/' . basename($old))
                    ->update(['stored_path' => null]);
            } catch (\Throwable $e) {
                Log::warning('[database-transfer] could not clear pruned safety path', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Audit rows are kept for a retention window; GC runs on the next import so no
     * extra scheduler entry is required. Never touches safety dumps. Aborted rows
     * (stale pending) are marked failed first so retention can reap them as well.
     */
    public function pruneAuditLogs(): void
    {
        $days = (int) config('database-transfer.audit_retention_days', 7);
        if ($days <= 0) {
            return;
        }

        $this->markStalePendingFailed();

        DatabaseTransferLog::where('created_at', '<', time() - $days * 86400)
            ->where('status', '!=', 'pending')
            ->delete();
    }

    /**
     * Try to acquire the single-restore lock. True when this caller got it.
     */
    public function acquireRestoreLock(): bool
    {
        $ttl = (int) config('database-transfer.restore_lock_ttl', 3600);

        return Cache::add(self::LOCK_KEY, 1, $ttl);
    }

    public function releaseRestoreLock(): void
    {
        Cache::forget(self::LOCK_KEY);
    }

    public function isRestoreLocked(): bool
    {
        return Cache::has(self::LOCK_KEY);
    }

    /**
     * Restore a dump file into the configured database. Throws on failure.
     */
    public function executeRestore(string $dumpPath, bool $isGz): void
    {
        if (!$this->isCliAvailable()) {
            throw ApiException::fail(__('Database client not available. Please rebuild the app image.'));
        }
        if (!file_exists($dumpPath)) {
            throw ApiException::fail(__('Dump file is missing before the restore started.'));
        }

        $import = $this->buildImportCommand($dumpPath, $isGz);
        $result = $this->run($import['cmd'], $import['env']);
        $combined = trim($result['out'] . "\n" . $result['err']);

        if ($result['code'] !== 0) {
            $msg = $this->redact($combined, (string) ($import['env']['MYSQL_PWD'] ?? ''));
            throw ApiException::fail(__('Database restore failed: :msg', [
                'msg' => $msg !== '' ? $msg : __('mysql exited with code :code.', ['code' => (string) $result['code']]),
            ]));
        }
    }

    /**
     * Strip the DB password from any text that may reach a log line or the UI.
     * $password is the actual MYSQL_PWD value (not shell-quoted) when available.
     */
    public function redact(string $text, string $password): string
    {
        if ($password !== '') {
            $text = str_replace($password, '***', $text);
        }

        return substr($text, 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Validate a full bundle tar.gz without extracting its full contents: gzip magic
     * + `tar -tzf` must succeed + the listing must contain dump.sql and manifest.json.
     */
    public function assertBundleIntegrity(string $path): void
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw ApiException::badRequest(__('Unable to read uploaded file.'));
        }
        $magic = fread($fh, 2);
        fclose($fh);
        if ($magic !== "\x1f\x8b") {
            throw ApiException::badRequest(__('File does not appear to be valid gzip.'));
        }

        $out = [];
        $code = null;
        @exec('gzip -t ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw ApiException::badRequest(__('Gzip file is truncated or corrupted: :msg', [
                'msg' => trim(substr(implode("\n", $out), 0, 200)),
            ]));
        }

        $list = $this->run('tar -tzf ' . escapeshellarg($path) . ' 2>&1');
        if ($list['code'] !== 0) {
            throw ApiException::badRequest(__('Uploaded tar archive is unreadable: :msg', [
                'msg' => substr(trim($list['out'] . "\n" . $list['err']), 0, 200),
            ]));
        }
        $entries = array_map('trim', explode("\n", trim($list['out'])));
        $normalize = fn (string $p): string => ltrim($p, './');
        $entries = array_map($normalize, $entries);
        if (!in_array('dump.sql', $entries, true)) {
            throw ApiException::badRequest(__('Full backup is missing dump.sql.'));
        }
        if (!in_array('manifest.json', $entries, true)) {
            throw ApiException::badRequest(__('Full backup is missing manifest.json.'));
        }
    }

    /**
     * Extract a bundle tar.gz into a private staging dir and return the paths needed
     * by the restore step. Every file is extracted under the staging prefix; no entry
     * is ever extracted to an absolute path or with .. traversal.
     *
     * @return array{stage: string, dump: string, manifest: array}
     */
    public function extractBundle(string $path): array
    {
        $stage = Storage::path((string) config('database-transfer.tmp_dir') . '/bundle_import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)));
        if (!@mkdir($stage, 0700, true)) {
            throw ApiException::fail(__('Could not prepare a staging directory for the import.'));
        }

        // --strip-components-safe is GNU-tar; keep extraction confined to $stage
        $result = $this->run('tar -xzf ' . escapeshellarg($path) . ' -C ' . escapeshellarg($stage) . ' 2>&1');
        if ($result['code'] !== 0) {
            $this->removeDir($stage);
            throw ApiException::badRequest(__('Could not extract the full backup archive: :msg', [
                'msg' => substr(trim($result['out'] . "\n" . $result['err']), 0, 300),
            ]));
        }

        $manifestPath = $stage . '/manifest.json';
        $dumpPath = $stage . '/dump.sql';
        if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
            $this->removeDir($stage);
            throw ApiException::badRequest(__('Full backup does not contain a usable dump.sql.'));
        }

        $manifest = [];
        if (is_file($manifestPath)) {
            $raw = @file_get_contents($manifestPath);
            $manifest = $raw !== false ? (json_decode($raw, true) ?: []) : [];
        }

        return ['stage' => $stage, 'dump' => $dumpPath, 'manifest' => $manifest];
    }

    /**
     * Copy config files from a bundle staging dir into the real config tree, then
     * refresh the config cache so the next request sees the restored settings.
     * Only the allowlist (v2board.php + config/theme/*.php) is ever copied; any
     * other entry in the bundle is ignored.
     */
    public function restoreConfigFromStage(string $stage, array $manifest): void
    {
        $allowed = $manifest['config'] ?? null;
        // Manifest-free bundles (hand-made tars) fall back to any config/theme file
        // present in the staging dir, but never outside that allowlist.
        if (!is_array($allowed) || $allowed === []) {
            $allowed = [];
            if (is_file($stage . '/config/v2board.php')) {
                $allowed[] = 'config/v2board.php';
            }
            foreach ((glob($stage . '/config/theme/*.php') ?: []) as $theme) {
                $allowed[] = 'config/theme/' . basename($theme);
            }
        }

        foreach ($allowed as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            // Reject traversal and any file outside the allowlist prefix.
            if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
                continue;
            }
            if ($rel !== 'config/v2board.php' && !str_starts_with($rel, 'config/theme/')) {
                continue;
            }
            if (!str_ends_with($rel, '.php')) {
                continue;
            }

            $src = $stage . '/' . $rel;
            if (!is_file($src)) {
                continue;
            }
            $dst = base_path() . '/' . $rel;
            @mkdir(dirname($dst), 0755, true);
            if (!@copy($src, $dst)) {
                Log::warning('[database-transfer] could not restore config file', ['file' => $rel]);
            }
        }

        try {
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            \Illuminate\Support\Facades\Artisan::call('config:cache');
        } catch (\Throwable $e) {
            Log::warning('[database-transfer] config:cache after bundle restore failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Execute a restore from any uploaded kind. For bundles this extracts the
     * dump.sql, restores the database, then restores the bundled config files.
     * $uploadedPath is always removed by the caller (controller or job) in finally.
     */
    public function executeRestoreFromUpload(string $uploadedPath, string $kind): void
    {
        if ($kind === self::KIND_BUNDLE) {
            $extracted = $this->extractBundle($uploadedPath);
            try {
                $this->assertLooksLikeSql($extracted['dump'], false);
                $this->executeRestore($extracted['dump'], false);
                $this->restoreConfigFromStage($extracted['stage'], $extracted['manifest']);
            } finally {
                $this->removeDir($extracted['stage']);
            }

            return;
        }

        $isGz = $kind === self::KIND_SQL_GZ;
        $this->assertLooksLikeSql($uploadedPath, $isGz);
        if ($isGz) {
            $this->assertGzIntegrity($uploadedPath);
        }
        $this->executeRestore($uploadedPath, $isGz);
    }

    /**
     * Create an audit log entry (who / what / outcome).
     */
    public function audit(
        int $userId,
        string $action,
        ?string $fileName,
        ?int $fileSize,
        string $status,
        ?string $message = null
    ): DatabaseTransferLog {
        $log = DatabaseTransferLog::create([
            'user_id' => $userId,
            'action' => $action,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'status' => $status,
            'message' => $message === null ? null : substr($message, 0, self::MAX_MESSAGE_LENGTH),
        ]);

        Log::info('[database-transfer] audit', [
            'user_id' => $userId,
            'action' => $action,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'status' => $status,
        ]);

        return $log;
    }

    /**
     * True when retained export copies are enabled and the export dir has room
     * for one more file of $sizeHint bytes (sizeHint 0 = unknown, only MIN_FREE applies).
     */
    public function canRetainExport(int $sizeHint = 0): bool
    {
        if (!config('database-transfer.keep_exports')) {
            return false;
        }
        $dir = (string) config('database-transfer.export_dir');
        $free = $this->freeSpaceAt($dir);

        return $free === null || $free >= self::MIN_FREE_BYTES + max(0, $sizeHint);
    }

    /**
     * Open a tee file for a streaming export: the browser still streams from the
     * mysqldump process, while an identical copy lands under tmp/ for retention.
     * Returns null when retention is disabled or the tmp file cannot be created;
     * null simply means "download succeeds, no server copy".
     */
    public function openExportTee(string $filename): ?string
    {
        if (!config('database-transfer.keep_exports')) {
            return null;
        }
        $this->ensureDirs();
        $tmpDir = (string) config('database-transfer.tmp_dir');
        $abs = Storage::path($tmpDir . '/export_tee_' . bin2hex(random_bytes(4)) . '.part');
        if (!@touch($abs)) {
            return null;
        }
        @chmod($abs, 0600);

        return $abs;
    }

    public function discardExportTee(?string $path): void
    {
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Move a completed export/safety file from tmp staging into the retained exports
     * dir (same storage volume, so rename() is the cheap path; copy+unlink when rename
     * refuses to cross devices) and point the audit row at it.
     *
     * @param string $absoluteStaged absolute path under storage/app
     * @return string|null storage-relative retained path, or null when the move failed
     */
    public function retainFile(DatabaseTransferLog $log, string $absoluteStaged, string $filename): ?string
    {
        if (!is_file($absoluteStaged) || filesize($absoluteStaged) === 0) {
            return null;
        }

        $this->ensureDirs();
        $exportDir = (string) config('database-transfer.export_dir');
        $absoluteExportDir = Storage::path($exportDir);
        if (!is_dir($absoluteExportDir) && !@mkdir($absoluteExportDir, 0700, true)) {
            Log::warning('[database-transfer] cannot create exports dir', ['dir' => $absoluteExportDir]);

            return null;
        }

        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) ?: 'export.sql.gz';
        $dest = $absoluteExportDir . '/' . $safe;
        if (is_file($dest)) {
            // Timestamped names should collide only within the same second; suffix instead
            // of clobbering another row's retained file.
            $dest = $absoluteExportDir . '/' . preg_replace(
                '/\.([a-z0-9.]+)$/i',
                '_' . bin2hex(random_bytes(2)) . '.$1',
                $safe,
                1
            );
        }

        if (!@rename($absoluteStaged, $dest) && !@copy($absoluteStaged, $dest)) {
            Log::warning('[database-transfer] could not retain export copy', ['dest' => $dest]);
            @unlink($dest);

            return null;
        }
        @unlink($absoluteStaged);
        @chmod($dest, 0600);

        $relative = $exportDir . '/' . basename($dest);
        try {
            $log->stored_path = $relative;
            $log->file_size = (int) filesize($dest);
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('[database-transfer] could not record retained path', ['error' => $e->getMessage()]);
        }
        $this->pruneRetainedExports();

        return $relative;
    }

    /**
     * Resolve an audit row's retained file for download, or null when there is none
     * (disabled, pruned, or never stored). Hard constraint: the path must live under
     * the retained-exports dir or the safety dir — never anything else on disk.
     */
    public function resolveStoredPath(?string $storedPath): ?string
    {
        if ($storedPath === null || trim($storedPath) === '') {
            return null;
        }
        if (str_contains($storedPath, '..') || str_starts_with($storedPath, '/') || str_contains($storedPath, "\0")) {
            return null;
        }

        foreach ([(string) config('database-transfer.export_dir'), (string) config('database-transfer.safety_dir')] as $dir) {
            $prefix = $dir . '/';
            if (strpos($storedPath, $prefix) === 0) {
                $abs = Storage::path($storedPath);
                if (is_file($abs)) {
                    return $abs;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Keep only the N most recent retained export copies (0 disables pruning).
     * Rows pointing at a pruned file have their stored_path cleared.
     */
    public function pruneRetainedExports(): void
    {
        $retention = (int) config('database-transfer.export_retention', 5);
        if ($retention <= 0) {
            return;
        }
        $exportDir = (string) config('database-transfer.export_dir');
        $absoluteDir = Storage::path($exportDir);
        if (!is_dir($absoluteDir)) {
            return;
        }

        $files = glob($absoluteDir . '/*') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        if (count($files) <= $retention) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $retention) as $old) {
            @unlink($old);
            try {
                DatabaseTransferLog::where('stored_path', $exportDir . '/' . basename($old))
                    ->update(['stored_path' => null]);
            } catch (\Throwable $e) {
                Log::warning('[database-transfer] could not clear pruned stored_path', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Set the outcome of an existing audit row; never throws (used from stream/job paths).
     * $fileSize is optional so export streams (whose byte total is only known after the
     * body is on the wire) can backfill it alongside the terminal status.
     */
    public function finish(DatabaseTransferLog|int $log, string $status, ?string $message = null, ?int $fileSize = null): void
    {
        try {
            $model = $log instanceof DatabaseTransferLog ? $log : DatabaseTransferLog::find($log);
            if (!$model) {
                return;
            }
            $model->status = $status;
            $model->message = $message === null ? null : substr($message, 0, self::MAX_MESSAGE_LENGTH);
            if ($fileSize !== null) {
                $model->file_size = $fileSize;
            }
            $model->save();
            // Stale pending rows (e.g. a download aborted mid-stream; the stream never
            // finishes) would sit forever — promotion heuristics inside isStalePending()
            // mark them failed so retention can reap them too.
            $this->markStalePendingFailed();
        } catch (\Throwable $e) {
            Log::warning('[database-transfer] could not update audit row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Pending rows older than the job timeout cannot belong to a running operation:
     * workers finish() or failed() within job_timeout, and export streams complete
     * in-request. Anything past that is an aborted download or a crashed worker.
     */
    public function markStalePendingFailed(): void
    {
        try {
            $staleAfter = max(600, (int) config('database-transfer.job_timeout', 3600));
            $cutoff = (int) time() - $staleAfter;
            // Never touch rows whose uploaded file could still be in flight: pending
            // imports are owned by a queue job protected by the restore lock.
            DatabaseTransferLog::where('status', 'pending')
                ->where('updated_at', '<', $cutoff)
                ->update(['status' => 'failed', 'message' => 'Operation did not complete (stale).']);
        } catch (\Throwable $e) {
            Log::warning('[database-transfer] stale-pending sweep failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Download filename: database name + timestamp, per the export requirement.
     */
    public function exportFilename(bool $gz): string
    {
        $db = (string) config('database.connections.mysql.database', 'v2board');
        // A DB name is operator-chosen; keep the header value safe.
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $db) ?? 'v2board';

        return sprintf('v2board_%s_%s.sql%s', $safe, date('Ymd-His'), $gz ? '.gz' : '');
    }

    public function fullBackupFilename(): string
    {
        $db = (string) config('database.connections.mysql.database', 'v2board');
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $db) ?? 'v2board';

        return sprintf('v2board_%s_%s_full.tar.gz', $safe, date('Ymd-His'));
    }
}
