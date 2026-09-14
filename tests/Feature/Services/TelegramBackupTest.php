<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Jobs\TelegramBackupJob;
use App\Models\DatabaseTransferLog;
use App\Models\User;
use App\Services\AuthService;
use App\Services\DatabaseTransferService;
use App\Services\TelegramBackupClient;
use App\Services\TelegramBackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\ProvisioningPlan;
use Mockery;
use Tests\TestCase;

class TelegramBackupTest extends TestCase
{
    private const TOKEN = '123456:dedicated_backup_token';
    private string $base;
    private TelegramBackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.telegram_backup_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('telegram_backup_test');
        foreach ([
            '2026_09_11_000000_create_database_transfer_logs_table.php',
            '2026_09_12_000000_add_stored_path_to_database_transfer_logs_table.php',
            '2026_09_14_000000_create_telegram_backup_settings_table.php',
        ] as $migration) {
            require_once database_path('migrations/' . $migration);
        }
        (new \CreateDatabaseTransferLogsTable())->up();
        (new \AddStoredPathToDatabaseTransferLogsTable())->up();
        (new \CreateTelegramBackupSettingsTable())->up();
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->boolean('banned')->default(false);
        });
        Storage::fake('local');
        Bus::fake();
        Http::fake();
        config(['database-transfer.keep_exports' => false, 'logging.default' => 'daily']);
        $securePath = (string) config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->base = '/api/v1/' . $securePath . '/database/telegram';
        $this->service = app(TelegramBackupService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('telegram_backup_test');
        parent::tearDown();
    }

    private function login(bool $admin = true): void
    {
        $id = DB::table('v2_user')->insertGetId(['email' => 'backup@example.test', 'is_admin' => $admin]);
        $user = User::findOrFail($id);
        $auth = (new AuthService($user))->generateAuthData(Request::create('/login'));
        $this->withHeader('authorization', $auth['auth_data']);
    }

    private function enable(int $hours = 24): void
    {
        $this->service->save([
            'enabled' => true,
            'bot_token' => self::TOKEN,
            'chat_id' => '-1001234567890',
            'interval_hours' => $hours,
        ]);
    }

    private function queuedJob(): TelegramBackupJob
    {
        return Bus::dispatched(TelegramBackupJob::class)->last();
    }

    private function fakeBundle(?callable $duringBuild = null): string
    {
        $path = Storage::path('full-test.tar.gz');
        file_put_contents($path, 'a full test archive');
        $transfer = Mockery::mock(DatabaseTransferService::class)->makePartial();
        $transfer->shouldReceive('buildFullBundle')->once()->andReturnUsing(function () use ($path, $duringBuild): array {
            if ($duringBuild) {
                $duringBuild();
            }

            return ['path' => $path, 'size' => filesize($path)];
        });
        $this->app->instance(DatabaseTransferService::class, $transfer);

        return $path;
    }

    public function testGuestAndNonAdminCannotReadSettingsSaveOrSendBackups(): void
    {
        $this->getJson($this->base)->assertForbidden();
        $this->postJson($this->base, ['enabled' => true])->assertForbidden();
        $this->postJson($this->base . '/backup')->assertForbidden();
        $this->login(false);
        $this->getJson($this->base)->assertForbidden();
        $this->postJson($this->base, ['enabled' => true])->assertForbidden();
        $this->postJson($this->base . '/backup')->assertForbidden();
        Bus::assertNothingDispatched();
    }

    public function testDefaultsAreDisabledAndCredentialsAreRequiredToEnable(): void
    {
        $this->login();
        $this->getJson($this->base)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.interval_hours', 24);
        $this->postJson($this->base, ['enabled' => true])->assertStatus(400);
        $this->postJson($this->base . '/backup')->assertStatus(400);
        $this->assertNull($this->service->enqueue(0, true));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function testSeparateTokenIsSavedWithoutBeingReturnedAndBlankKeepsIt(): void
    {
        $this->login();
        config(['v2board.telegram_bot_token' => 'main-bot-token']);
        $this->postJson($this->base, [
            'enabled' => true,
            'bot_token' => self::TOKEN,
            'chat_id' => '-1001234567890',
            'interval_hours' => 6,
        ])->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.bot_token_configured', true)->assertDontSee(self::TOKEN);
        $this->assertSame(self::TOKEN, $this->service->settings()->bot_token);
        $this->postJson($this->base, ['bot_token' => '', 'interval_hours' => 12])->assertOk()->assertDontSee(self::TOKEN);
        $this->getJson($this->base)->assertDontSee(self::TOKEN)->assertDontSee('main-bot-token');
        $this->assertSame(self::TOKEN, $this->service->settings()->bot_token);
        $this->assertSame('main-bot-token', config('v2board.telegram_bot_token'));
        $this->assertArrayNotHasKey('bot_token', $this->service->settings()->toArray());
    }

    public function testSettingsRejectInvalidTokenChatAndIntervals(): void
    {
        $this->login();
        foreach ([['bot_token' => 'https://evil.test'], ['chat_id' => '@unexpected'], ['interval_hours' => 0], ['interval_hours' => 169], ['interval_hours' => 1.5]] as $invalid) {
            $this->postJson($this->base, $invalid)->assertStatus(422);
        }
    }

    public function testManualBackupQueuesOnceAndPreservesSchedule(): void
    {
        $this->login();
        $this->enable();
        $next = $this->service->settings()->next_run_at;
        $response = $this->postJson($this->base . '/backup')->assertStatus(202);
        $this->postJson($this->base . '/backup')->assertStatus(409);
        $this->assertNull($this->service->enqueue(0, true));
        Bus::assertDispatchedTimes(TelegramBackupJob::class, 1);
        $job = $this->queuedJob();
        $this->assertSame($response->json('data.id'), $job->logId);
        $this->assertSame($next, $this->service->settings()->next_run_at);
        $this->assertStringNotContainsString(self::TOKEN, serialize($job));
        $this->assertSame(1, (int) DatabaseTransferLog::findOrFail($job->logId)->user_id);
    }

    public function testScheduleWaitsForDueTimeThenAdvancesAndDoesNotCatchUpRepeatedly(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->enable(6);
        $this->assertNull($this->service->enqueue(0, true));
        $this->travel(7)->hours();
        $this->artisan('backup:telegram')->assertExitCode(0);
        $job = $this->queuedJob();
        $this->assertSame(now()->timestamp + 6 * 3600, $this->service->settings()->next_run_at);
        $this->service->failed($job->logId, $job->lockOwner);
        $this->artisan('backup:telegram')->assertExitCode(0);
        Bus::assertDispatchedTimes(TelegramBackupJob::class, 1);
    }

    public function testSavingUnchangedSettingsDoesNotResetScheduleOrCancelJob(): void
    {
        $this->enable();
        $before = $this->service->settings();
        $this->travel(2)->hours();
        $this->service->save(['enabled' => true, 'bot_token' => '', 'chat_id' => $before->chat_id, 'interval_hours' => 24]);
        $after = $this->service->settings();
        $this->assertSame($before->next_run_at, $after->next_run_at);
        $this->assertSame($before->revision, $after->revision);
    }

    public function testDisablingCancelsAlreadyQueuedJobAndClearsNextRun(): void
    {
        $this->enable();
        $this->service->enqueue(1);
        $job = $this->queuedJob();
        $this->service->save(['enabled' => false]);
        $job->handle($this->service);
        $this->assertNull($this->service->settings()->next_run_at);
        $this->assertSame('failed', DatabaseTransferLog::findOrFail($job->logId)->status);
        Http::assertNothingSent();
        $this->enable();
        $this->assertNotNull($this->service->enqueue(1));
    }

    public function testChangingDestinationDuringBuildPreventsSendingAndCleansArchive(): void
    {
        $this->enable();
        $this->service->enqueue(1);
        $path = $this->fakeBundle(function (): void {
            $this->service->save(['chat_id' => '12345']);
        });
        $job = $this->queuedJob();
        $job->handle(app(TelegramBackupService::class));
        $this->assertSame('failed', DatabaseTransferLog::findOrFail($job->logId)->status);
        $this->assertFileDoesNotExist($path);
        Http::assertNothingSent();
    }

    public function testSuccessUploadsFullFileWithDedicatedTokenAndNeverSendsTwice(): void
    {
        $this->enable();
        $this->service->enqueue(1);
        $path = $this->fakeBundle();
        Http::swap(new Factory());
        Http::fake(function ($request, $options) {
            $this->assertStringContainsString('a full test archive', $request->body());
            $this->assertStringContainsString('-1001234567890', $request->body());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(10, $options['connect_timeout']);

            return Http::response(['ok' => true, 'result' => ['message_id' => 42]]);
        });
        $job = $this->queuedJob();
        $job->handle(app(TelegramBackupService::class));
        $log = DatabaseTransferLog::findOrFail($job->logId);
        $this->assertSame('success', $log->status);
        $this->assertSame(19, (int) $log->file_size);
        $this->assertFileDoesNotExist($path);
        Http::assertSent(function ($request) use ($log): bool {
            return $request->url() === 'https://api.telegram.org/bot' . self::TOKEN . '/sendDocument'
                && $request->method() === 'POST'
                && $request->hasFile('document', null, $log->file_name);
        });
        $job->handle(app(TelegramBackupService::class));
        Http::assertSentCount(1);
        $this->assertNotNull($this->service->enqueue(1));
    }

    public function testFailedUploadRetainsDownloadableArchiveAndRedactsTelegramError(): void
    {
        $this->enable();
        $this->service->enqueue(1);
        $path = $this->fakeBundle();
        config(['database-transfer.keep_exports' => true, 'database-transfer.min_free_space_mb' => 0]);
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => self::TOKEN], 403)]);
        $job = $this->queuedJob();
        $job->handle(app(TelegramBackupService::class));
        $log = DatabaseTransferLog::findOrFail($job->logId);
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString(self::TOKEN, $log->message);
        $this->assertFileDoesNotExist($path);
        $this->assertNotNull(app(DatabaseTransferService::class)->resolveStoredPath($log->stored_path));
        $this->assertNotNull($this->service->enqueue(1));
    }

    public function testOversizedArchiveIsRejectedBeforeAnyNetworkRequest(): void
    {
        $path = Storage::path('too-large.tar.gz');
        $file = fopen($path, 'wb');
        ftruncate($file, TelegramBackupClient::MAX_FILE_BYTES + 1);
        fclose($file);
        try {
            app(TelegramBackupClient::class)->sendDocument(self::TOKEN, '12345', $path, 'full.tar.gz');
            $this->fail('Expected oversized file rejection.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('50 MB', $e->getMessage());
            Http::assertNothingSent();
        } finally {
            unlink($path);
        }
    }

    public function testMalformedOrUnconfirmedTelegramResponseIsNotSuccess(): void
    {
        $path = Storage::path('test.tar.gz');
        file_put_contents($path, 'archive');
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['ok' => true])]);
        try {
            app(TelegramBackupClient::class)->sendDocument(self::TOKEN, '12345', $path, 'full.tar.gz');
            $this->fail('Expected unconfirmed delivery to fail.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('did not confirm delivery', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function testRestoreDefersScheduledBackupAndRejectsManualRequest(): void
    {
        $this->enable(1);
        $this->travel(2)->hours();
        $transfer = app(DatabaseTransferService::class);
        $transfer->acquireRestoreLock();
        $this->assertNull($this->service->enqueue(0, true));
        try {
            $this->service->enqueue(1);
            $this->fail('Expected restore conflict.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            $transfer->releaseRestoreLock();
        }
        Bus::assertNothingDispatched();
    }

    public function testBuildFailureAndWorkerTimeoutReleaseTheSlot(): void
    {
        $this->enable();
        $this->service->enqueue(1);
        $transfer = Mockery::mock(DatabaseTransferService::class)->makePartial();
        $transfer->shouldReceive('buildFullBundle')->once()->andThrow(ApiException::fail('Dump failed.'));
        $this->app->instance(DatabaseTransferService::class, $transfer);
        $job = $this->queuedJob();
        $job->handle(app(TelegramBackupService::class));
        $this->assertSame('failed', DatabaseTransferLog::findOrFail($job->logId)->status);
        $this->service->enqueue(1);
        $next = $this->queuedJob();
        Cache::put(DatabaseTransferService::LOCK_KEY, $next->lockOwner, 3900);
        $next->failed(new \RuntimeException('Do not log ' . self::TOKEN));
        $this->assertFalse(app(DatabaseTransferService::class)->isRestoreLocked());
        $this->assertStringNotContainsString(self::TOKEN, DatabaseTransferLog::findOrFail($next->logId)->message);
        $this->assertNotNull($this->service->enqueue(1));
        Http::assertNothingSent();
    }

    public function testFailureCleanupDoesNotReleaseAnotherTransferLock(): void
    {
        $this->enable();
        $this->service->enqueue(1);
        $job = $this->queuedJob();
        app(DatabaseTransferService::class)->acquireRestoreLock();
        $job->failed(new \RuntimeException('Worker stopped.'));
        $this->assertTrue(app(DatabaseTransferService::class)->isRestoreLocked());
    }

    public function testExpiredJobCannotReleaseANewerReservation(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->enable();
        $this->service->enqueue(1);
        $old = $this->queuedJob();
        $this->travel(3)->hours();
        $this->service->enqueue(1);
        $old->handle($this->service);
        $this->assertSame('failed', DatabaseTransferLog::findOrFail($old->logId)->status);
        $this->expectException(ApiException::class);
        $this->service->enqueue(1);
    }

    public function testBackupWorkerExistsAlongsideExistingQueuesAndHasSafeTimeouts(): void
    {
        $plan = ProvisioningPlan::get('test');
        $this->assertNotNull($plan->optionsFor('local', 'V2board'));
        foreach (['local', '*'] as $environment) {
            $options = $plan->optionsFor($environment, 'TelegramBackup');
            $this->assertSame('telegram_backup', $options->connection);
            $this->assertSame('telegram_backup', $options->queue);
            $this->assertLessThan(config('queue.connections.telegram_backup.retry_after'), $options->timeout);
        }
    }

    public function testSavingDatabaseErrorDoesNotExposeTheToken(): void
    {
        $this->login();
        DB::statement("CREATE TRIGGER reject_settings BEFORE UPDATE ON v2_telegram_backup_setting BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->postJson($this->base, ['bot_token' => self::TOKEN])->assertStatus(500)->assertDontSee(self::TOKEN);
    }

    public function testRequestLoggerOmitsTheBackupToken(): void
    {
        Schema::create('v2_log', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['title', 'level', 'host', 'uri', 'method', 'ip', 'data', 'context', 'created_at', 'updated_at'] as $column) {
                $table->text($column)->nullable();
            }
        });
        $request = Request::create('/database/telegram', 'POST', ['bot_token' => self::TOKEN, 'interval_hours' => 6]);
        $this->app->instance('request', $request);
        Log::channel('mysql')->warning('Backup settings request');
        $record = DB::table('v2_log')->first();
        $this->assertStringNotContainsString(self::TOKEN, $record->data);
        $this->assertSame(6, json_decode($record->data, true)['interval_hours']);
    }

    public function testAdminPageRendersTheDisabledBackupControlsWithoutToken(): void
    {
        $this->login();
        $this->enable();
        $html = view('database-transfer', ['secure_path' => 'admin', 'auth_data' => 'test-auth'])->render();
        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('onchange="toggleTelegram(this.checked)"', $html);
        $this->assertStringNotContainsString('id="telegramToggle" type="button"', $html);
        $this->assertStringContainsString('Back up now', $html);
        $this->assertStringContainsString('id="telegramNow" type="button" onclick="backupTelegramNow()" disabled', $html);
        $this->assertStringContainsString("headers['Authorization'] = auth", $html);
        $this->assertStringNotContainsString("headers['authorization'] = auth", $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
    }
}
