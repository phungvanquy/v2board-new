<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TelegramBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TelegramBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;
    public $timeout = 3600;
    public $failOnTimeout = true;

    public function __construct(public int $logId, public string $revision, public string $lockOwner)
    {
        $this->onConnection('telegram_backup');
        $this->onQueue('telegram_backup');
    }

    public function handle(TelegramBackupService $service): void
    {
        $service->run($this->logId, $this->revision, $this->lockOwner);
    }

    public function failed(\Throwable $exception): void
    {
        app(TelegramBackupService::class)->failed($this->logId, $this->lockOwner);
    }
}
