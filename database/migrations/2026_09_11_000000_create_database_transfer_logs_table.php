<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDatabaseTransferLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('v2_database_transfer_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable()->comment('admin who triggered the transfer');
            $table->string('action', 16)->comment('export|import');
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 16)->comment('pending|success|failed');
            $table->text('message')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_database_transfer_log');
    }
}
