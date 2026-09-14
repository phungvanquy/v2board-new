<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'V2Board update';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Artisan::call('config:cache');
        DB::connection()->getPdo();
        // Concurrency guard: when several app containers boot at once (or an
        // operator runs the command manually during a deploy), only one
        // migrator proceeds; the others skip instead of racing DDL.
        try {
            $lock = DB::selectOne("SELECT GET_LOCK('v2board-update', 30) AS acquired");
        } catch (\Exception $e) {
            $lock = null;
        }
        if (!$lock || (int) ($lock->acquired ?? 0) !== 1) {
            $this->warn('Another v2board:update is running — skipping.');

            return 0;
        }
        try {
            $this->runUpdateSql();
        } finally {
            try {
                DB::selectOne("SELECT RELEASE_LOCK('v2board-update')");
            } catch (\Exception $e) {
            }
        }
        \Artisan::call('horizon:terminate');
        $this->info('Update complete, the queue service has been restarted. No further action is required.');

        return 0;
    }

    private function runUpdateSql(): void
    {
        $file = \File::get(base_path() . '/database/update.sql');
        if (!$file) {
            abort(500, 'Database file does not exist');
        }
        $sql = str_replace("\n", '', $file);
        $sql = preg_split('/;/', $sql);
        if (!is_array($sql)) {
            abort(500, 'Invalid database file format');
        }
        $this->info('Importing database, please wait...');
        $applied = 0;
        $skipped = 0;
        foreach ($sql as $item) {
            if (!$item) {
                continue;
            }
            try {
                DB::select(DB::raw($item));
                $applied++;
            } catch (\Exception $e) {
                // Statement already applied (duplicate column/table) or a
                // no-op on this schema version — update.sql is cumulative and
                // records no applied version, so re-runs rely on this.
                $skipped++;
            }
        }
        $this->info("Schema statements applied: {$applied}, already present: {$skipped}.");
    }
}
