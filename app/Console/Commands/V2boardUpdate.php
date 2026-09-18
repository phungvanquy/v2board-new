<?php

namespace App\Console\Commands;

use App\Services\ServerIdService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $lock = DB::connection()->selectOne(
                'SELECT GET_LOCK(?, 30) AS acquired',
                [ServerIdService::MIGRATION_LOCK_NAME],
                false
            );
        } catch (\Exception $e) {
            $lock = null;
        }
        if (!$lock || (int) ($lock->acquired ?? 0) !== 1) {
            $this->warn('Another v2board:update is running — skipping.');

            return 0;
        }
        try {
            $this->runUpdateSql();
            $this->ensureServerSequence();
        } finally {
            try {
                DB::connection()->selectOne(
                    'SELECT RELEASE_LOCK(?) AS released',
                    [ServerIdService::MIGRATION_LOCK_NAME],
                    false
                );
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

    /**
     * Provision or repair v2_server_sequence under the v2board-update
     * advisory lock. CREATE TABLE IF NOT EXISTS in update.sql cannot fix
     * v1.0.1 databases where the table already exists with the broken
     * schema (next_id as PK). Doing this here serializes the DDL and
     * preserves the high-water mark.
     */
    private function ensureServerSequence(): void
    {
        $table = 'v2_server_sequence';

        try {
            if (!Schema::hasTable($table)) {
                Schema::create($table, function ($t) {
                    $t->tinyInteger('id')->unsigned()->primary();
                    $t->bigInteger('next_id');
                });
                $seed = $this->maxLiveServerId() + 1;
                DB::table($table)->insert(['id' => 1, 'next_id' => $seed]);
                $this->info("Created {$table} seeded at {$seed}.");

                return;
            }

            // Legacy broken schema: `next_id` was the PK, no `id` column.
            // update.sql's CREATE TABLE IF NOT EXISTS leaves it untouched
            // and INSERT IGNORE (id, next_id) fails silently.
            if (!Schema::hasColumn($table, 'id')) {
                $maxNext = null;
                try {
                    $maxNext = DB::table($table)->max('next_id');
                } catch (\Exception $e) {
                }
                // Drop may implicitly commit on MySQL — safe here because we
                // are not inside a user transaction, only under GET_LOCK.
                Schema::drop($table);
                Schema::create($table, function ($t) {
                    $t->tinyInteger('id')->unsigned()->primary();
                    $t->bigInteger('next_id');
                });
                $seed = $maxNext !== null ? (int) $maxNext : $this->maxLiveServerId() + 1;
                $seed = max($seed, $this->maxLiveServerId() + 1);
                DB::table($table)->insert(['id' => 1, 'next_id' => $seed]);
                $this->info("Repaired {$table} (legacy PK) seeded at {$seed}.");

                return;
            }

            // Correct schema but may have been left empty or seeded at 1 by a
            // previous buggy update.sql run that created a duplicate row.
            // Collapse to single row if needed.
            $count = 0;
            try {
                $count = (int) DB::table($table)->count();
            } catch (\Exception $e) {
            }
            if ($count === 0) {
                $seed = $this->maxLiveServerId() + 1;
                DB::table($table)->insert(['id' => 1, 'next_id' => $seed]);
                $this->info("Seeded empty {$table} at {$seed}.");
            } elseif ($count > 1) {
                // Legacy of the buggy PK: two rows with different next_id.
                // Keep the high-water mark.
                $maxNext = DB::table($table)->max('next_id');
                DB::table($table)->delete();
                $seed = $maxNext !== null ? (int) $maxNext : $this->maxLiveServerId() + 1;
                $seed = max($seed, $this->maxLiveServerId() + 1);
                DB::table($table)->insert(['id' => 1, 'next_id' => $seed]);
                $this->info("Repaired {$table} duplicate rows, kept seed {$seed}.");
            }
        } catch (\Throwable $e) {
            $this->error("Could not ensure {$table}: " . $e->getMessage());
            throw $e;
        }
    }

    private function maxLiveServerId(): int
    {
        $max = 0;
        foreach ([
            'v2_server_vless',
            'v2_server_vmess',
            'v2_server_trojan',
            'v2_server_shadowsocks',
            'v2_server_hysteria',
            'v2_server_tuic',
            'v2_server_anytls',
            'v2_server_v2node',
        ] as $tbl) {
            try {
                if (!Schema::hasTable($tbl)) {
                    continue;
                }
                $v = DB::table($tbl)->max('id');
                if ($v !== null && $v > $max) {
                    $max = (int) $v;
                }
            } catch (\Exception $e) {
            }
        }

        return $max;
    }
}
