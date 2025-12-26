<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('sync_status', 20)->nullable()->after('analysis_status')->comment('同步狀態：updated（更新）、synced（已同步）、parsed（已解析）');
            $table->index('sync_status', 'idx_sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex('idx_sync_status');
            $table->dropColumn('sync_status');
        });
    }
};

