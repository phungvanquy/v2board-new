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
     * Atomically allocate the next globally-unique id from the persistent
     * sequence. The sequence is monotonic and never reuses deleted IDs.
     * Uses SELECT ... FOR UPDATE on the sequence row, so concurrent callers
     * are serialized. If the sequence table is empty or behind live data
     * (e.g. after a dump restore), it self-heals to maxGlobalId()+1.
     *
     * @throws \RuntimeException on lock/transaction failure
     */
    private static function allocateId(): int
    {
        return DB::transaction(function () {
            // Ensure the sequence table/row exists (fresh sqlite in tests, or
            // pre-migration DB). Create lazily if needed.
            if (!Schema::hasTable(self::SEQ_TABLE)) {
                Schema::create(self::SEQ_TABLE, function ($table) {
                    $table->bigInteger('next_id')->primary();
                });
                $seed = self::maxGlobalId() + 1;
                DB::table(self::SEQ_TABLE)->insert(['next_id' => $seed + 1]);

                return $seed;
            }

            $row = DB::table(self::SEQ_TABLE)->lockForUpdate()->first();

            if (!$row) {
                $seed = self::maxGlobalId() + 1;
                DB::table(self::SEQ_TABLE)->insert(['next_id' => $seed + 1]);

                return $seed;
            }

            $next = (int) $row->next_id;
            $highWater = self::maxGlobalId() + 1;

            // Self-heal if sequence fell behind (dump restore, manual insert).
            if ($next < $highWater) {
                $next = $highWater;
            }

            DB::table(self::SEQ_TABLE)->update(['next_id' => $next + 1]);

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
