<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Admin\DatabaseTransferController;
use App\Services\DatabaseTransferService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Controller-level coverage for the admin database transfer surface.
 *
 * The CI image (php:8.2-cli) has no MySQL, so tests here are written to assert the
 * paths that run BEFORE any database work (auth gate, confirmation, lock, validation)
 * and to guard the rest behind a DB-availability check.
 */
class DatabaseTransferHttpTest extends TestCase
{
    private function securePath(): string
    {
        return (string) config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
    }

    public function testAdminRoutesAreRegistered(): void
    {
        $base = 'api/v1/' . $this->securePath() . '/database';
        $uriMap = [];
        foreach (Route::getRoutes() as $route) {
            $uriMap[$route->uri()] = $route->methods();
        }

        $this->assertArrayHasKey($base . '/export', $uriMap);
        $this->assertArrayHasKey($base . '/import', $uriMap);
        $this->assertArrayHasKey($base . '/status/{id}', $uriMap);
        $this->assertArrayHasKey($base . '/history', $uriMap);
        $this->assertArrayHasKey($base . '/download/{id}', $uriMap);
        $this->assertContains('GET', $uriMap[$base . '/export']);
        $this->assertContains('POST', $uriMap[$base . '/import']);
    }

    public function testUnauthenticatedRequestsAreRejected(): void
    {
        $base = '/api/v1/' . $this->securePath() . '/database';

        // The admin middleware aborts before any controller/DB work.
        $this->getJson($base . '/export')->assertStatus(403);
        $this->getJson($base . '/history')->assertStatus(403);
        $this->getJson($base . '/status/1')->assertStatus(403);
        $this->getJson($base . '/download/1')->assertStatus(403);
        $this->postJson($base . '/import', ['confirm' => 'RESTORE'])->assertStatus(403);
    }

    public function testResolveStoredPathRejectsTraversalAndForeignDirs(): void
    {
        $service = app(DatabaseTransferService::class);

        $this->assertNull($service->resolveStoredPath(null));
        $this->assertNull($service->resolveStoredPath(''));
        $this->assertNull($service->resolveStoredPath('../../.env'));
        $this->assertNull($service->resolveStoredPath('/etc/passwd'));
        $this->assertNull($service->resolveStoredPath("database-backups/exports/x\0y"));
        // A path in a dir that is neither exports nor pre-restore is never downloadable.
        $this->assertNull($service->resolveStoredPath('database-backups/tmp/secret.sql'));
    }

    public function testOpenExportTeeRespectsKeepExportsFlag(): void
    {
        Storage::fake('local');
        $service = app(DatabaseTransferService::class);

        config(['database-transfer.keep_exports' => false]);
        $this->assertNull($service->openExportTee('x.sql.gz'));

        config(['database-transfer.keep_exports' => true]);
        $tee = $service->openExportTee('x.sql.gz');
        $this->assertNotNull($tee);
        $this->assertTrue(is_file($tee));
        $service->discardExportTee($tee);
        $this->assertFalse(is_file($tee));
    }

    public function testBladePageWithoutTokenServesLoginBridgeNotData(): void
    {
        // The page itself is a shell; all data flows through the admin-gated API.
        // Without ?auth_data it must not render the transfer UI — it renders the
        // bridge view (200) which explains how to authenticate.
        $response = $this->get('/' . $this->securePath() . '/database');
        $response->assertStatus(200);
        $this->assertStringContainsString('Not logged in', $response->getContent());
        $this->assertStringNotContainsString('doImport', $response->getContent());
    }

    public function testBladePageWithInvalidTokenServesLoginBridge(): void
    {
        $response = $this->get('/' . $this->securePath() . '/database?auth_data=not-a-jwt');
        $response->assertStatus(200);
        $this->assertStringContainsString('Not logged in', $response->getContent());
    }

    public function testImportRequiresTypedConfirmation(): void
    {
        $this->expectException(ApiException::class);

        $request = Request::create('/database/import', 'POST');
        $request->merge(['user' => ['id' => 1], 'confirm' => 'restore']);

        app(DatabaseTransferController::class)->import($request);
    }

    public function testConcurrentImportIsRejectedWith409(): void
    {
        $service = app(DatabaseTransferService::class);
        $this->assertTrue($service->acquireRestoreLock());

        Storage::fake('local');
        $request = $this->importRequest(UploadedFile::fake()->createWithContent('dump.sql', "CREATE TABLE `t` (`id` int);\n"));

        try {
            app(DatabaseTransferController::class)->import($request);
            $this->fail('Expected a 409 conflict while a restore is in flight.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            $service->releaseRestoreLock();
        }
    }

    public function testInvalidUploadIsRejectedWithoutTouchingDatabase(): void
    {
        Storage::fake('local');
        $cases = [
            'wrong extension' => UploadedFile::fake()->createWithContent('notes.txt', 'CREATE TABLE `t` (`id` int);'),
            'empty sql' => UploadedFile::fake()->createWithContent('empty.sql', ''),
        ];

        foreach ($cases as $label => $file) {
            $request = $this->importRequest($file);
            try {
                app(DatabaseTransferController::class)->import($request);
                $this->fail("Expected rejection for {$label}.");
            } catch (ApiException $e) {
                $this->assertSame(400, $e->getStatusCode(), "{$label} should be a 400");
            }
        }

        // The "not a SQL dump" rejection is exercised at the service level — its
        // controller path also writes an audit row, which would need a DB in CI
        // (php:8.2-cli has no pdo_mysql). The service path has all the signal.
        $temp = tempnam(sys_get_temp_dir(), 'notsql');
        file_put_contents($temp, str_repeat("not sql\n", 20));
        try {
            $this->expectException(ApiException::class);
            app(DatabaseTransferService::class)->assertLooksLikeSql($temp, false);
        } finally {
            @unlink($temp);
        }
    }

    public function testTruncatedGzipIsRejected(): void
    {
        // A gz stream whose first block decodes as SQL still fails the CRC check.
        $raw = tempnam(sys_get_temp_dir(), 'gztest');
        $gz = gzopen($raw, 'wb9');
        gzwrite($gz, "CREATE TABLE `t` (`id` int);\n");
        gzclose($gz);
        $bytes = file_get_contents($raw);
        unlink($raw);

        // Chop off the 8-byte gzip trailer so only the header + data survive.
        $truncated = substr($bytes, 0, -8);
        $temp = tempnam(sys_get_temp_dir(), 'gzpart');
        file_put_contents($temp, $truncated);
        // Keep the file around for the controller upload wrapper.
        $this->assertNotFalse($temp);

        $service = app(DatabaseTransferService::class);

        try {
            $service->assertLooksLikeSql($temp, true);
            // The head check passes (valid SQL before the trailer) — integrity must not.
            $this->expectException(ApiException::class);
            $service->assertGzIntegrity($temp);
        } finally {
            @unlink($temp);
        }
    }

    public function testOversizedUploadIsRejected(): void
    {
        config(['database-transfer.max_upload_size' => 1024]);
        $this->expectException(ApiException::class);

        app(DatabaseTransferService::class)->validateUpload(
            UploadedFile::fake()->create('big.sql', 2048) // KB → 2 MB
        );
    }

    public function testSafetyBackupFailureRequiresAcknowledgement(): void
    {
        // Backup disabled on this server: a restore must be refused unless skipped explicitly.
        config(['database-transfer.safety_backup_enabled' => false]);
        $service = app(DatabaseTransferService::class);

        try {
            $service->ensureSafetyBackup(false);
            $this->fail('Expected refusal when a safety backup is unavailable.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('skip_safety_backup', $e->getMessage());
        }

        // Acknowledged: no exception, no dump.
        $result = $service->ensureSafetyBackup(true);
        $this->assertFalse($result['created']);
    }

    public function testAuditLogRecordsExportOutcome(): void
    {
        // Writing the audit row needs a database; CI (php:8.2-cli) has none.
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database available: ' . $e->getMessage());
        }

        $service = app(DatabaseTransferService::class);
        $log = $service->audit(2, 'export', 'dump.sql.gz', 1234, 'success', 'Export completed');

        $this->assertNotNull($log->id);
        $fresh = $log->fresh();
        $this->assertSame('export', $fresh->action);
        $this->assertSame('success', $fresh->status);
        $this->assertSame('dump.sql.gz', $fresh->file_name);

        $service->finish($log, 'failed', 'Simulated failure');
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame('Simulated failure', $log->fresh()->message);

        // Clean up so the assertion above is the only observable effect.
        $log->delete();
    }

    private function importRequest(UploadedFile $file): Request
    {
        $request = Request::create('/database/import', 'POST', ['confirm' => DatabaseTransferService::CONFIRM_PHRASE], [], ['file' => $file]);
        $request->merge(['user' => ['id' => 1]]);

        return $request;
    }
}
