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
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->tinyInteger('importance_rating')->nullable()->after('importance_score')->comment('重要性評分 (1-5，5為最高)');
            $table->index('importance_rating', 'idx_importance_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->dropIndex('idx_importance_rating');
            $table->dropColumn('importance_rating');
        });
    }
};
