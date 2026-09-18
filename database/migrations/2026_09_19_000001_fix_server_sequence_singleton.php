<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixServerSequenceSingletonKey extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_sequence')) {
            return;
        }

        // If already fixed (has stable `id` column), nothing to do.
        if (Schema::hasColumn('v2_server_sequence', 'id')) {
            return;
        }

        // Legacy schema: `next_id` was the PK with a single mutable row.
        // If an update already inserted a duplicate row, keep the highest.
        $maxNext = DB::table('v2_server_sequence')->max('next_id');

        Schema::drop('v2_server_sequence');

        Schema::create('v2_server_sequence', function (Blueprint $table) {
            $table->tinyInteger('id')->unsigned()->primary();
            $table->bigInteger('next_id');
        });

        $seed = $maxNext !== null ? (int) $maxNext : 1;

        // Ensure we didn't lose high-water from live server tables after the
        // legacy table was seeded with 1 and never self-healed correctly.
        $maxLive = 0;
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
            if (!Schema::hasTable($tbl)) {
                continue;
            }
            $v = DB::table($tbl)->max('id');
            if ($v !== null && $v > $maxLive) {
                $maxLive = (int) $v;
            }
        }
        $seed = max($seed, $maxLive + 1);

        DB::table('v2_server_sequence')->insert(['id' => 1, 'next_id' => $seed]);
    }

    public function down(): void
    {
        // No rollback — keeps the fixed schema.
    }
}
