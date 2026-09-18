<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateServerIdSequenceTable extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_sequence', function (Blueprint $table) {
            // Stable singleton key — the mutable counter lives in next_id.
            $table->tinyInteger('id')->unsigned()->primary();
            $table->bigInteger('next_id');
        });

        // Seed to max existing server id + 1 across all protocol tables.
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
            if (!Schema::hasTable($tbl)) {
                continue;
            }
            $v = DB::table($tbl)->max('id');
            if ($v !== null && $v > $max) {
                $max = (int) $v;
            }
        }
        DB::table('v2_server_sequence')->insert(['id' => 1, 'next_id' => $max + 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_sequence');
    }
}
