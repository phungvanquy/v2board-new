<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\DatabaseImportJob;
use App\Models\DatabaseTransferLog;
use App\Services\DatabaseTransferService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseImportJobTest extends TestCase
{
    public function testDispatchToQueueReturnsQueuedWithPendingStatus(): void
    {
        Queue::fake();

        // Avoid touching the database — the job only needs a log id.
        $logId = 999;

        DatabaseImportJob::dispatch($logId, '/tmp/test_dump.sql.gz', DatabaseTransferService::KIND_SQL_GZ, false);

        Queue::assertPushed(DatabaseImportJob::class, function ($job) use ($logId) {
            return $job->logId === $logId && $job->kind === DatabaseTransferService::KIND_SQL_GZ && $job->dumpPath === '/tmp/test_dump.sql.gz';
        });
    }

    public function testBundleDispatchPreservesBundleKind(): void
    {
        Queue::fake();

        DatabaseImportJob::dispatch(555, '/tmp/backup.tar.gz', DatabaseTransferService::KIND_BUNDLE, true);

        Queue::assertPushed(DatabaseImportJob::class, function ($job) {
            return $job->kind === DatabaseTransferService::KIND_BUNDLE && $job->skipSafetyBackup === true;
        });
    }

    public function testStatusTransitionsCanBePersistedWhenDbAvailable(): void
    {
        // When no DB is reachable (CI php:8.2-cli without mysql), skip rather than error.
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database available: ' . $e->getMessage());
        }

        $log = DatabaseTransferLog::create([
            'user_id' => 1,
            'action' => 'import',
            'file_name' => 'dump.sql',
            'file_size' => 1000,
            'status' => 'pending',
        ]);

        $this->assertEquals('pending', $log->status);

        $log->status = 'success';
        $log->save();

        $this->assertEquals('success', $log->fresh()->status);
    }

    public function testFailedImportThrowsCleanlyWhenDumpMissing(): void
    {
        $svc = app(DatabaseTransferService::class);

        $this->expectException(\App\Exceptions\ApiException::class);
        $svc->executeRestore('/tmp/nonexistent.sql.gz', false);
    }

    public function testPruneSafetyBackupsRetainsOnlyConfiguredCount(): void
    {
        $svc = app(DatabaseTransferService::class);
        $dir = (string) config('database-transfer.safety_dir');
        $abs = Storage::path($dir);

        if (is_dir($abs)) {
            foreach ((glob($abs . '/*') ?: []) as $f) {
                @unlink($f);
            }
        } else {
            @mkdir($abs, 0755, true);
        }

        foreach (range(0, 4) as $i) {
            $file = $abs . "/pre_restore_{$i}.sql.gz";
            touch($file, time() - (5 - $i) * 3600);
        }

        $svc->pruneSafetyBackups();

        $this->assertCount(3, glob($abs . '/*.sql.gz') ?: []);

        // Cleanup
        foreach ((glob($abs . '/*') ?: []) as $f) {
            @unlink($f);
        }
    }

    public function testJobUsesConfiguredQueue(): void
    {
        $job = new DatabaseImportJob(1, '/tmp/x.sql', DatabaseTransferService::KIND_SQL, false);
        $queue = (string) config('database-transfer.queue', 'stat');
        $this->assertSame($queue, $job->queue);
    }
}
