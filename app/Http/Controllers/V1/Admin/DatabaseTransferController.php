<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\DatabaseImportJob;
use App\Models\DatabaseTransferLog;
use App\Services\DatabaseTransferService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseTransferController extends Controller
{
    private DatabaseTransferService $service;

    public function __construct(DatabaseTransferService $service)
    {
        $this->service = $service;
    }

    /**
     * Stream a mysqldump download.
     *
     * GET /{secure_path}/database/export?format=gz|sql|full
     * full -> v2board_<db>_<ts>_full.tar.gz (dump.sql + config files + manifest)
     */
    public function export(Request $request): StreamedResponse
    {
        $format = strtolower((string) $request->query('format', 'gz'));
        $isFull = $format === 'full' || $format === 'bundle' || $format === 'tar.gz';

        if (!$this->service->isCliAvailable()) {
            throw ApiException::fail(__('Database export is unavailable: mysqldump is not installed on this server. Rebuild the app image.'));
        }

        $userId = (int) ($request->input('user')['id'] ?? 0);

        if ($isFull) {
            $filename = $this->service->fullBackupFilename();
            $log = $this->service->audit($userId, 'export', $filename, null, 'pending', null);
            $bundle = $this->service->buildFullBundle();
            $path = $bundle['path'];
            $size = $bundle['size'];
            $safeFilename = addslashes($filename);

            $headers = [
                'Content-Type' => 'application/gzip',
                'Content-Length' => (string) $size,
                'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
                'X-Content-Type-Options' => 'nosniff',
            ];

            return new StreamedResponse(function () use ($path, $log, $size, $filename): void {
                @set_time_limit(0);
                $fh = @fopen($path, 'rb');
                if ($fh === false) {
                    $this->service->finish($log, 'failed', __('Full backup file is missing before the download started.'));

                    return;
                }
                while (!feof($fh)) {
                    $chunk = fread($fh, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
                fclose($fh);
                try {
                    $this->service->finish($log, 'success', __('Export completed (:bytes bytes).', ['bytes' => (string) $size]), $size);
                    // The bundle already exists as a file; retention is just a rename
                    // into the exports dir (or unlink when retention is off / disk is low).
                    if ($this->service->canRetainExport($size)) {
                        $this->service->retainFile($log, $path, $filename);
                    } else {
                        @unlink($path);
                    }
                } catch (\Throwable $e) {
                    @unlink($path);
                }
            }, 200, $headers);
        }

        $gz = $format === 'gz' || $format === 'gzip' || $format === 'compressed';
        $filename = $this->service->exportFilename($gz);

        // The headers are not trusted to repeat this value; if the client needs the
        // original filename later they obtain it from Content-Disposition instead.
        $log = $this->service->audit($userId, 'export', $filename, null, 'pending', null);

        // Same audit-structure guarantee as the full bundle: the streaming dump carries
        // no audit data (--ignore-table in buildDumpCommand), and this block appends the
        // idempotent CREATE IF NOT EXISTS so the dump keeps "all tables present".
        $cmd = $this->service->buildExportCommand($gz, $this->service->auditStructureBlock());
        $env = (array) $this->service->buildDumpCommand(null)['env'];
        $safeFilename = addslashes($filename);
        $retain = $this->service->canRetainExport();
        // A tee file lets us retain an exact copy without buffering the dump in memory.
        $teePath = $retain ? $this->service->openExportTee($filename) : null;

        $headers = [
            'Content-Type' => $gz ? 'application/gzip' : 'application/octet-stream',
            // Filter the header token per RFC 6266 so an operator-chosen DB name cannot split it.
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new StreamedResponse(function () use ($cmd, $env, $log, $teePath, $filename): void {
            @set_time_limit(0);
            // Write dumps direct to the downstream; stderr stays on pipe 2.
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // No MYSQL_PWD on the command line — proc_open() environ is used instead.
            $process = @proc_open('sh -c ' . escapeshellarg($cmd), $descriptors, $pipes, base_path(), array_merge(getenv() ?: [], $env));
            if (!is_resource($process)) {
                $this->service->finish($log, 'failed', __('Could not start the export process.'));
                $this->service->discardExportTee($teePath);

                return;
            }

            fclose($pipes[0]);

            $bytesEmitted = 0;
            $teeBytes = 0;
            $tee = $teePath !== null ? @fopen($teePath, 'wb') : false;
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 32768);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                // The tee is best-effort: a retain-disk failure must never break a download.
                if ($tee !== false && @fwrite($tee, $chunk) === false) {
                    @fclose($tee);
                    $tee = false;
                    $this->service->discardExportTee($teePath);
                    $teePath = null;
                } else {
                    $teeBytes += strlen($chunk);
                }
                $bytesEmitted += strlen($chunk);
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }

            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $code = proc_close($process);

            try {
                if ($code !== 0) {
                    // mysqldump wrote nothing useful on non-zero (and its stderr would have
                    // been emitted into the stream, breaking the file), so record a failure
                    // with a sanitized message while accepting that the already-sent bytes
                    // are truncated.
                    $this->service->finish($log, 'failed', $this->service->redact(
                        (string) $stderr ?: __('mysqldump exited with code :code.', ['code' => (string) $code]),
                        (string) ($env['MYSQL_PWD'] ?? '')
                    ), $bytesEmitted);
                    $this->service->discardExportTee($teePath);
                } elseif ($bytesEmitted === 0) {
                    $this->service->finish($log, 'failed', __('mysqldump produced no output.'), 0);
                    $this->service->discardExportTee($teePath);
                } else {
                    $this->service->finish($log, 'success', __('Export completed (:bytes bytes).', ['bytes' => (string) $bytesEmitted]), $bytesEmitted);
                    if ($teePath !== null && $tee !== false) {
                        @fclose($tee);
                        $this->service->retainFile($log, $teePath, $filename);
                    }
                }
            } catch (\Throwable $e) {
                // The response body is already on the wire; best-effort only.
            }
        }, 200, $headers);
    }

    /**
     * Upload and restore a dump.
     *
     * POST /{secure_path}/database/import  (multipart: file, confirm=RESTORE)
     * On failure the dump is discarded without touching the database. On success
     * a single queued import is allowed at a time and the lock is released under
     * finally / job tail logic so a stuck worker cannot wedge the endpoint.
     */
    public function import(Request $request)
    {
        $confirm = (string) $request->input('confirm', '');
        if ($confirm !== DatabaseTransferService::CONFIRM_PHRASE) {
            throw ApiException::badRequest(__('Please type :phrase exactly to confirm the restore.', [
                'phrase' => DatabaseTransferService::CONFIRM_PHRASE,
            ]));
        }

        if ($this->service->isRestoreLocked()) {
            throw new ApiException(409, __('Another database backup or restore is already in progress. Please wait.'));
        }

        if (!$request->hasFile('file')) {
            throw ApiException::badRequest(__('No file uploaded.'));
        }

        $file = $request->file('file');

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $msg = match ($file->getError()) {
                UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_INI_SIZE => __('The uploaded file hit the server upload limit (upload_max_filesize / nginx client_max_body_size). Increase the limits and try again.'),
                UPLOAD_ERR_PARTIAL => __('Upload was interrupted. Please try again.'),
                default => __('Upload failed (code :code).', ['code' => (string) $file->getError()]),
            };
            throw new ApiException(413, $msg);
        }

        $meta = $this->service->validateUpload($file);
        $kind = $meta['kind'];
        $isGz = $meta['is_gz'];
        $size = $meta['size'];
        $originalName = $meta['original_name'];

        $skipSafety = (bool) $request->boolean('skip_safety_backup', false);

        // The disk write can throw; nothing in the database has happened yet.
        try {
            $storedPath = $this->service->storeUpload($file, $kind);
        } catch (ApiException $e) {
            $userId = (int) ($request->input('user')['id'] ?? 0);
            $this->service->audit($userId, 'import', $originalName, $size, 'failed', $e->getMessage());
            throw $e;
        }

        $userId = (int) ($request->input('user')['id'] ?? 0);

        try {
            if ($kind === \App\Services\DatabaseTransferService::KIND_BUNDLE) {
                $this->service->assertBundleIntegrity($storedPath);
            } else {
                $this->service->assertLooksLikeSql($storedPath, $isGz);
                if ($isGz) {
                    $this->service->assertGzIntegrity($storedPath);
                }
            }
        } catch (ApiException $e) {
            @unlink($storedPath);
            $this->service->audit($userId, 'import', $originalName, $size, 'failed', $e->getMessage());
            throw $e;
        }

        $asyncThreshold = (int) config('database-transfer.async_threshold');
        $useAsync = $size > $asyncThreshold;
        // Extracting a full bundle tar before touching the DB applies non-trivial disk
        // work; running that inline would hold the FPM worker. Queue large bundles even
        // when they sit just under the byte threshold — waiting a worker is the safer
        // thing for a destructive operation that also writes config files.
        if ($kind === \App\Services\DatabaseTransferService::KIND_BUNDLE) {
            $extractHintBytes = 5 * 1024 * 1024;
            if ($size > $extractHintBytes) {
                $useAsync = true;
            }
        }

        if (!$this->service->acquireRestoreLock()) {
            @unlink($storedPath);
            throw new ApiException(409, __('Another database backup or restore is already in progress. Please wait.'));
        }

        // Best-effort GC of old audit rows — no extra cron required.
        try {
            $this->service->pruneAuditLogs();
        } catch (\Throwable $e) {
            // Advisory only.
        }

        $log = $this->service->audit($userId, 'import', $originalName, $size, 'pending', $useAsync ? 'Queued' : 'Running');

        if ($useAsync) {
            // A full mysqldump replay can run many seconds; keep that work out
            // of the web process and in a queue the deployment's worker watches
            // (config/horizon.php and config/database-transfer.php must agree).
            DatabaseImportJob::dispatch($log->id, $storedPath, $kind, $skipSafety);

            return response([
                'data' => [
                    'id' => $log->id,
                    'status' => 'pending',
                    'message' => __('Restore queued. Poll status for progress.'),
                ],
            ]);
        }

        try {
            $safety = $this->service->ensureSafetyBackup($skipSafety);
            // Link the import row to the safety dump it just produced: Download on an
            // import row = "give me the pre-restore state so I can roll this back".
            if (!empty($safety['path'])) {
                $log->stored_path = $safety['path'];
                $log->save();
            }
            $this->service->executeRestoreFromUpload($storedPath, $kind);

            $this->service->finish($log, 'success', __('Restore completed.'));

            return response([
                'data' => [
                    'id' => $log->id,
                    'status' => 'success',
                    'message' => __('Restore completed.'),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->service->finish($log, 'failed', $e->getMessage());
            throw $e;
        } finally {
            @unlink($storedPath);
            $this->service->releaseRestoreLock();
        }
    }

    /**
     * Poll status of a transfer operation.
     *
     * GET /{secure_path}/database/status/{id}
     */
    public function status(Request $request, int $id)
    {
        $log = DatabaseTransferLog::find($id);
        if (!$log) {
            throw ApiException::notFound(__('Transfer not found.'));
        }

        return response([
            'data' => [
                'id' => $log->id,
                'action' => $log->action,
                'file_name' => $log->file_name,
                'file_size' => $log->file_size,
                'status' => $log->status,
                'message' => $log->message,
                'has_file' => $this->service->resolveStoredPath($log->stored_path) !== null,
                'created_at' => $log->created_at,
                'updated_at' => $log->updated_at,
            ],
        ]);
    }

    /**
     * Download a retained copy of a past export (or the pre-restore safety dump an
     * import produced). Admin-gated like every other route here; returns 404 when no
     * file is retained for the row (disabled, pruned, or never stored).
     *
     * GET /{secure_path}/database/download/{id}
     */
    public function download(Request $request, int $id): StreamedResponse
    {
        $log = DatabaseTransferLog::find($id);
        if (!$log) {
            throw ApiException::notFound(__('Transfer not found.'));
        }

        $abs = $this->service->resolveStoredPath($log->stored_path);
        if ($abs === null) {
            throw ApiException::notFound(__('No file is retained for this record. Retention may be disabled or the copy was pruned.'));
        }

        $filename = $log->file_name ?: basename($abs);
        $safeFilename = addslashes($filename);
        $size = (int) @filesize($abs);

        return new StreamedResponse(function () use ($abs): void {
            @set_time_limit(0);
            $fh = @fopen($abs, 'rb');
            if ($fh === false) {
                return;
            }
            while (!feof($fh)) {
                $chunk = fread($fh, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }
            fclose($fh);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Most recent transfers first.
     *
     * GET /{secure_path}/database/history?current=1&page_size=10
     */
    public function history(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = (int) $request->input('page_size', 10);
        $pageSize = max(10, min(50, $pageSize));

        $total = DatabaseTransferLog::count();
        $rows = DatabaseTransferLog::orderBy('id', 'desc')
            ->forPage($current, $pageSize)
            ->get();

        // Do not ship raw stored_path values — they are server-relative and a client
        // has no use for them. has_file is enough for the UI to offer Download.
        $rows->transform(function (DatabaseTransferLog $row): array {
            $array = $row->toArray();
            $array['has_file'] = $this->service->resolveStoredPath($row->stored_path) !== null;
            unset($array['stored_path']);

            return $array;
        });

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }
}
