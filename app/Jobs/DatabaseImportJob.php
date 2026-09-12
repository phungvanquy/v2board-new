<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DatabaseTransferLog;
use App\Services\DatabaseTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DatabaseImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public int $timeout;

    public function __construct(
        public int $logId,
        public string $dumpPath,
        public string $kind = DatabaseTransferService::KIND_SQL,
        public bool $skipSafetyBackup = false
    ) {
        $configTimeout = (int) config('database-transfer.job_timeout', 3600);
        $this->timeout = $configTimeout;
        $queue = (string) config('database-transfer.queue', 'stat');
        $this->onQueue($queue);
    }

    public function handle(DatabaseTransferService $service): void
    {
        $log = DatabaseTransferLog::find($this->logId);
        if (!$log) {
            Log::warning('[database-transfer] import job: log not found', ['log_id' => $this->logId]);
            @unlink($this->dumpPath);

            return;
        }

        if (!file_exists($this->dumpPath) || $this->isMissingImportLimit($this->dumpPath)) {
            $service->finish($log, 'failed', __('Import job: the uploaded dump is empty or missing on disk.'));
            @unlink($this->dumpPath);
            $service->releaseRestoreLock();

            return;
        }

        try {
            if (!$this->skipSafetyBackup) {
                $safety = $service->ensureSafetyBackup(false);
                // Link the import row to the safety dump it produced (same as the sync
                // controller path) so History can offer a pre-restore rollback download.
                if (!empty($safety['path'])) {
                    $log->stored_path = $safety['path'];
                    $log->save();
                }
            }

            // kind drives whether we extract a full bundle (dump.sql + config files)
            // or feed a single .sql/.sql.gz straight to mysql. executeRestoreFromUpload
            // dispatches on the kind and throws before touching anything when a bundle
            // is unreadable.
            $service->executeRestoreFromUpload($this->dumpPath, $this->kind);

            $service->finish($log, 'success', 'Restore completed');
        } catch (\Throwable $e) {
            $service->finish($log, 'failed', $e->getMessage());
            Log::error('[database-transfer] async import failed', ['log_id' => $this->logId, 'error' => $e->getMessage()]);
        } finally {
            $service->pruneAuditLogs();
            @unlink($this->dumpPath);
            $service->releaseRestoreLock();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $service = app(DatabaseTransferService::class);
        $service->finish($this->logId, 'failed', $exception->getMessage());

        Log::error('[database-transfer] import job failed (queue worker failure)', [
            'log_id' => $this->logId,
            'error' => $exception->getMessage(),
        ]);
        @unlink($this->dumpPath);
        $service->releaseRestoreLock();
    }

    /**
     * Treat an absent file or the default nginx/PHP post_max_size truncation as a
     * missing import: the uploaded tmp file never reached the queue with its full bytes.
     * nginx truncates with no error, PHP leaves an upload error on the side; neither
     * one is recoverable as "SQL".
     */
    private function isMissingImportLimit(string $path): bool
    {
        // nginx/client_max_body_size style truncation can produce a zero-byte file.
        return filesize($path) === 0;
    }
}
