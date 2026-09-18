<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServerIdService
{
    private const MODELS = [
        \App\Models\ServerVless::class,
        \App\Models\ServerVmess::class,
        \App\Models\ServerTrojan::class,
        \App\Models\ServerShadowsocks::class,
        \App\Models\ServerHysteria::class,
        \App\Models\ServerTuic::class,
        \App\Models\ServerAnytls::class,
        \App\Models\ServerV2node::class,
    ];

    private const SEQ_TABLE = 'v2_server_sequence';

    /**
     * Return the maximum node id across all protocol tables.
     */
    public static function maxGlobalId(): int
    {
        $max = 0;
        foreach (self::MODELS as $model) {
            $v = $model::max('id');
            if ($v !== null && $v > $max) {
                $max = (int) $v;
            }
        }

        return $max;
    }

    /**
     * Ensure the sequence table exists and is seeded. Idempotent and safe
     * to call outside a transaction. Uses CREATE TABLE IF NOT EXISTS so it
     * never races. Called before the allocation transaction.
     */
    private static function ensureSequenceReady(): void
    {
        if (Schema::hasTable(self::SEQ_TABLE)) {
            // Migrate legacy broken schema (next_id as PK) if still present.
            if (!Schema::hasColumn(self::SEQ_TABLE, 'id')) {
                // Let the fix migration handle it; if it hasn't run, do an
                // inline repair that is safe to run concurrently: read max,
                // drop, recreate with stable key. Another worker racing here
                // will see hasColumn true after the drop/create.
                try {
                    $maxNext = DB::table(self::SEQ_TABLE)->max('next_id');
                    Schema::drop(self::SEQ_TABLE);
                    Schema::create(self::SEQ_TABLE, function ($table) {
                        $table->tinyInteger('id')->unsigned()->primary();
                        $table->bigInteger('next_id');
                    });
                    $seed = $maxNext !== null ? (int) $maxNext : self::maxGlobalId() + 1;
                    $seed = max($seed, self::maxGlobalId() + 1);
                    DB::table(self::SEQ_TABLE)->insert(['id' => 1, 'next_id' => $seed]);
                } catch (\Throwable $e) {
                    if (!Schema::hasColumn(self::SEQ_TABLE, 'id')) {
                        throw $e;
                    }
                }
            }

            return;
        }

        // DDL outside any transaction — on MySQL CREATE TABLE would
        // implicitly commit and break the caller's transaction.
        try {
            Schema::create(self::SEQ_TABLE, function ($table) {
                $table->tinyInteger('id')->unsigned()->primary();
                $table->bigInteger('next_id');
            });
        } catch (\Throwable $e) {
            // Another worker created it concurrently, or DDL not permitted.
            if (!Schema::hasTable(self::SEQ_TABLE)) {
                throw $e;
            }
        }

        // Seed if empty. Use INSERT IGNORE to handle concurrent seeding.
        $count = 0;
        try {
            $count = (int) DB::table(self::SEQ_TABLE)->where('id', 1)->count();
        } catch (\Throwable $e) {
            // Table exists but not yet queryable — treat as empty.
        }

        if ($count === 0) {
            $seed = self::maxGlobalId() + 1;
            try {
                DB::table(self::SEQ_TABLE)->insert(['id' => 1, 'next_id' => $seed]);
            } catch (\Throwable $e) {
                // Concurrent insert won the race — ignore duplicate key.
                if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Atomically allocate the next globally-unique id from the persistent
     * sequence. The sequence is monotonic and never reuses deleted IDs.
     * Uses SELECT ... FOR UPDATE on the singleton row (id=1), so concurrent
     * callers are serialized. If the sequence fell behind live data (e.g.
     * after a dump restore), it self-heals to maxGlobalId()+1.
     *
     * @throws \RuntimeException on lock/transaction failure
     */
    private static function allocateId(): int
    {
        self::ensureSequenceReady();

        return DB::transaction(function () {
            $row = DB::table(self::SEQ_TABLE)->where('id', 1)->lockForUpdate()->first();

            if (!$row) {
                // Row was deleted — re-seed from live data.
                $seed = self::maxGlobalId() + 1;
                DB::table(self::SEQ_TABLE)->insert(['id' => 1, 'next_id' => $seed + 1]);

                return $seed;
            }

            $next = (int) $row->next_id;
            $highWater = self::maxGlobalId() + 1;

            // Self-heal if sequence fell behind (dump restore, manual insert).
            if ($next < $highWater) {
                $next = $highWater;
            }

            DB::table(self::SEQ_TABLE)->where('id', 1)->update(['next_id' => $next + 1]);

            return $next;
        }, 3);
    }

    /**
     * Create a server model with a globally-unique id drawn from the
     * persistent sequence. Retries once on duplicate-key (e.g. manual
     * INSERT raced the sequence after a restore).
     */
    public static function createWithGlobalId(string $modelClass, array $params): Model
    {
        $lastException = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = self::allocateId();

            try {
                // Bypass `guarded = ['id']` by direct assignment.
                $model = new $modelClass();
                $model->fill($params);
                $model->id = $id;
                $model->save();

                return $model;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'Duplicate entry') || str_contains($msg, 'UNIQUE constraint failed')) {
                    $lastException = $e;
                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new \RuntimeException('Failed to allocate globally-unique server id after retries');
    }

    /**
     * Allocate and reserve the next id without creating a server row.
     * The id is consumed even if the caller does not use it.
     */
    public static function nextId(): int
    {
        return self::allocateId();
    }
}
