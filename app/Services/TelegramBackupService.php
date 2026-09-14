<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\TelegramBackupJob;
use App\Models\DatabaseTransferLog;
use App\Models\TelegramBackupSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramBackupService
{
    public const ACTION = 'telegram_backup';
    public const LOCK_KEY = 'telegram_backup_run';
    public const LOCK_SECONDS = 7200;

    public function __construct(private DatabaseTransferService $transfer, private TelegramBackupClient $client)
    {
    }

    public function settings(): TelegramBackupSetting
    {
        // Query on every run so long-lived Horizon workers honor changes immediately.
        /** @var TelegramBackupSetting $settings */
        $settings = TelegramBackupSetting::query()->findOrFail(1);

        return $settings;
    }

    public function summary(): array
    {
        $settings = $this->settings();
        /** @var DatabaseTransferLog|null $latest */
        $latest = DatabaseTransferLog::query()->where('action', self::ACTION)->orderByDesc('id')->first();

        return [
            'enabled' => $settings->enabled,
            'bot_token_configured' => $settings->bot_token !== '',
            'chat_id' => $settings->chat_id,
            'interval_hours' => $settings->interval_hours,
            'next_run_at' => $settings->next_run_at,
            'last_backup' => $latest ? [
                'id' => $latest->id,
                'status' => $latest->status,
                'message' => $latest->message,
                'created_at' => $latest->created_at,
            ] : null,
        ];
    }

    public function save(array $data): void
    {
        try {
            DB::transaction(function () use ($data): void {
                $query = TelegramBackupSetting::query();
                $query->lockForUpdate();
                /** @var TelegramBackupSetting $settings */
                $settings = $query->findOrFail(1);
                foreach (['enabled', 'chat_id', 'interval_hours'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $settings->{$key} = $data[$key] ?? '';
                    }
                }
                // An empty password field means keep the saved token. Never return it.
                if (!empty($data['bot_token'])) {
                    $settings->bot_token = $data['bot_token'];
                }
                if ($settings->enabled) {
                    $this->assertConfigured($settings);
                }
                if ($settings->isDirty()) {
                    $settings->revision = bin2hex(random_bytes(16));
                    $settings->next_run_at = $settings->enabled ? now()->timestamp + $settings->interval_hours * 3600 : null;
                    $settings->save();
                }
            });
        } catch (QueryException $e) {
            // SQL exceptions include bound values, including the backup bot token.
            throw ApiException::fail(__('Could not save Telegram backup settings. Check the database and try again.'));
        }
    }

    private function assertConfigured(TelegramBackupSetting $settings): void
    {
        if ($settings->bot_token === '' || $settings->chat_id === '') {
            throw ApiException::badRequest(__('Set a backup bot token and chat ID before enabling Telegram backups.'));
        }
    }

    /** Queue at most one backup across manual clicks and scheduler instances. */
    public function enqueue(int $userId = 0, bool $scheduled = false): ?int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (!$lock->get()) {
            if ($scheduled) {
                return null;
            }
            throw new ApiException(409, __('A Telegram backup is already queued or running.'));
        }

        $log = null;
        try {
            $settings = $this->settings();
            if (!$settings->enabled) {
                if ($scheduled) {
                    $lock->release();

                    return null;
                }
                throw ApiException::badRequest(__('Enable Telegram backups before using Back up now.'));
            }
            if ($scheduled && ($settings->next_run_at === null || $settings->next_run_at > now()->timestamp)) {
                $lock->release();

                return null;
            }
            $this->assertConfigured($settings);
            if ($this->transfer->isRestoreLocked()) {
                if ($scheduled) {
                    $lock->release();

                    return null;
                }
                throw new ApiException(409, __('A restore is in progress. Try the Telegram backup after it finishes.'));
            }

            $log = $this->transfer->audit(
                $userId,
                self::ACTION,
                $this->transfer->fullBackupFilename(),
                null,
                'pending',
                $scheduled ? __('Scheduled Telegram backup queued.') : __('Manual Telegram backup queued.')
            );

            // A failed scheduled attempt waits until the next interval; manual
            // retries are available. Updating by revision preserves concurrent edits.
            if ($scheduled) {
                TelegramBackupSetting::query()->where('id', 1)->where('revision', $settings->revision)
                    ->update(['next_run_at' => now()->timestamp + $settings->interval_hours * 3600]);
            }
            Bus::dispatch(new TelegramBackupJob($log->id, $settings->revision, $lock->owner()));

            return $log->id;
        } catch (\Throwable $e) {
            $lock->release();
            if ($log) {
                $this->transfer->finish($log, 'failed', __('Could not queue the Telegram backup. Check that Redis and the backup worker are available.'));
                throw ApiException::fail(__('Could not queue the Telegram backup. Check that Redis and the backup worker are available.'));
            }
            throw $e;
        }
    }

    public function run(int $logId, string $revision, string $owner): void
    {
        $lock = Cache::restoreLock(self::LOCK_KEY, $owner);
        $path = null;
        $size = null;
        $settings = null;
        $restoreLockHeld = false;
        /** @var DatabaseTransferLog|null $log */
        $log = DatabaseTransferLog::query()->find($logId);
        // Claim the audit row atomically as well as reserving the queue slot.
        // Duplicate queue deliveries must never send the archive a second time.
        if (!$log || DatabaseTransferLog::query()->where('id', $logId)->where('status', 'pending')->update(['status' => 'running']) !== 1) {
            return;
        }
        try {
            // Leave a full worker timeout inside the reservation lifetime. A
            // delayed job cannot outlive its slot and overlap a later backup.
            if ((int) $log->created_at <= now()->timestamp - (self::LOCK_SECONDS - 3600)) {
                throw ApiException::fail(__('The queued Telegram backup expired. Start a new backup.'));
            }
            $settings = $this->settings();
            $this->assertCurrent($settings, $revision);
            // Share the restore exclusion key, with an owner and a TTL longer
            // than this job's timeout so failure cleanup can release only ours.
            $restoreLockHeld = Cache::add(DatabaseTransferService::LOCK_KEY, $owner, 3900);
            if (!$restoreLockHeld) {
                throw ApiException::fail(__('Telegram backup was cancelled because a database restore is in progress.'));
            }
            $bundle = $this->transfer->buildFullBundle();
            $path = $bundle['path'];
            $size = $bundle['size'];

            // Recheck after dumping too: disabling or changing the destination
            // while the archive is built must prevent delivery with stale settings.
            $this->assertCurrent($this->settings(), $revision);
            $this->client->sendDocument($settings->bot_token, $settings->chat_id, $path, (string) $log->file_name);
            $this->transfer->finish($log, 'success', __('Full backup delivered to Telegram.'), $size);
        } catch (\Throwable $e) {
            $message = $e instanceof ApiException ? $e->getMessage() : __('Telegram backup failed. Check the server and try again.');
            if ($settings !== null && $settings->bot_token !== '') {
                $message = str_replace($settings->bot_token, '[redacted]', $message);
            }
            $this->transfer->finish($logId, 'failed', $message, $size);
            Log::warning('[telegram-backup] backup failed', ['log_id' => $logId, 'message' => $message]);
        } finally {
            try {
                // Keep a downloadable full archive even when Telegram rejects it,
                // subject to the existing export retention settings.
                if ($path !== null && $this->transfer->canRetainExport($size ?? 0)) {
                    $log->refresh();
                    $this->transfer->retainFile($log, $path, (string) $log->file_name);
                }
            } catch (\Throwable $e) {
                Log::warning('[telegram-backup] could not retain archive', ['log_id' => $logId]);
            } finally {
                if ($path !== null) {
                    @unlink($path);
                }
                if ($restoreLockHeld) {
                    $this->releaseTransferLock($owner);
                }
                $lock->release();
            }
        }
    }

    private function assertCurrent(TelegramBackupSetting $settings, string $revision): void
    {
        if (!$settings->enabled || $settings->revision !== $revision) {
            throw ApiException::fail(__('Telegram backup cancelled because its settings changed or the feature was disabled.'));
        }
        $this->assertConfigured($settings);
    }

    public function failed(int $logId, string $owner): void
    {
        $this->transfer->finish($logId, 'failed', __('The Telegram backup worker stopped or timed out. Check the destination before trying again.'));
        $this->releaseTransferLock($owner);
        Cache::restoreLock(self::LOCK_KEY, $owner)->release();
    }

    private function releaseTransferLock(string $owner): void
    {
        if (Cache::get(DatabaseTransferService::LOCK_KEY) === $owner) {
            $this->transfer->releaseRestoreLock();
        }
    }
}
