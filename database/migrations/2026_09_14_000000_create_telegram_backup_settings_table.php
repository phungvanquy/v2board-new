<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTelegramBackupSettingsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_telegram_backup_setting')) {
            return;
        }
        Schema::create('v2_telegram_backup_setting', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('bot_token', 200)->default('');
            $table->string('chat_id', 32)->default('');
            $table->unsignedInteger('interval_hours')->default(24);
            $table->unsignedBigInteger('next_run_at')->nullable();
            $table->string('revision', 64)->default('');
        });

        DB::table('v2_telegram_backup_setting')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_backup_setting');
    }
}
