<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * Create a server model with a globally-unique id.
     * Uses MySQL advisory lock to serialize concurrent allocations;
     * falls back to plain max+1 with duplicate-key retry on other drivers.
     *
     * Works with models that have `guarded = ['id']` by assigning id directly.
     */
    public static function createWithGlobalId(string $modelClass, array $params): Model
    {
        $driver = config('database.connections.' . config('database.default') . '.driver');
        $useLock = $driver === 'mysql';

        // Retry loop handles the narrow race where two requests compute same max+1.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $locked = false;
            if ($useLock) {
                try {
                    $res = DB::selectOne("SELECT GET_LOCK('v2board_server_id', 5) AS l");
                    $locked = isset($res->l) && (int) $res->l === 1;
                } catch (\Throwable $e) {
                    $useLock = false;
                }
            }

            $id = self::maxGlobalId() + 1;

            try {
                // Bypass `guarded = ['id']` by direct assignment
                $model = new $modelClass();
                $model->fill($params);
                $model->id = $id;
                $model->save();
                if ($locked) {
                    try {
                        DB::selectOne("SELECT RELEASE_LOCK('v2board_server_id')");
                    } catch (\Throwable $e) {
                    }
                }

                return $model;
            } catch (\Throwable $e) {
                if ($locked) {
                    try {
                        DB::selectOne("SELECT RELEASE_LOCK('v2board_server_id')");
                    } catch (\Throwable $ex) {
                    }
                }
                // Duplicate primary key -> retry with new max
                $msg = $e->getMessage();
                if (str_contains($msg, 'Duplicate entry') || str_contains($msg, 'UNIQUE constraint failed')) {
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Failed to allocate globally-unique server id after retries');
    }

    /**
     * Allocate next id only (for callers that need the id value).
     * Prefer createWithGlobalId() for creation to keep lock held across read+write.
     */
    public static function nextId(): int
    {
        $driver = config('database.connections.' . config('database.default') . '.driver');
        if ($driver === 'mysql') {
            $locked = false;
            try {
                $res = DB::selectOne("SELECT GET_LOCK('v2board_server_id', 5) AS l");
                $locked = isset($res->l) && (int) $res->l === 1;
            } catch (\Throwable $e) {
            }
            $id = self::maxGlobalId() + 1;
            if ($locked) {
                try {
                    DB::selectOne("SELECT RELEASE_LOCK('v2board_server_id')");
                } catch (\Throwable $e) {
                }
            }

            return $id;
        }

        return self::maxGlobalId() + 1;
    }
}
