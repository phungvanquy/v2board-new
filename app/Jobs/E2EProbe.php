<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Smoke-test job for `docs/docker.md` Horizon verification.
 * Dispatch to a queue Horizon actually watches, e.g.:
 *   dispatch((new E2EProbe)->onQueue('stat'));
 * then poll Cache::get('E2E_JOB'). Delete if you don't need it.
 */
class E2EProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Cache::forever('E2E_JOB', 'processed');
    }
}
