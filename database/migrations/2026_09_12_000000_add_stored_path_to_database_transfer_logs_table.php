<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStoredPathToDatabaseTransferLogsTable extends Migration
{
    public function up(): void
    {
        Schema::table('v2_database_transfer_log', function (Blueprint $table) {
            // Relative path (under storage/app) of a retained server-side copy an admin
            // can re-download from History; null when nothing was retained (imports,
            // failed/aborted exports, or retention disabled/pruned).
            $table->string('stored_path', 512)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('v2_database_transfer_log', function (Blueprint $table) {
            $table->dropColumn('stored_path');
        });
    }
}
