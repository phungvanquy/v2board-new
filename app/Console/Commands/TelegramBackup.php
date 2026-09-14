<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class TelegramBackup extends Command
{
    protected $signature = 'backup:telegram';

    protected $description = 'Queue a full Telegram backup when its configured schedule is due';

    public function handle(TelegramBackupService $service): int
    {
        // Keep existing scheduler installations usable during a rolling upgrade.
        if (!Schema::hasTable('v2_telegram_backup_setting')) {
            return 0;
        }
        $id = $service->enqueue(0, true);
        if ($id !== null) {
            $this->info('Queued Telegram backup #' . $id . '.');
        }

        return 0;
    }
}
