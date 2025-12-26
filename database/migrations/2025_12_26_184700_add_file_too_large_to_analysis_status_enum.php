<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 修改 analysis_status ENUM，添加 'file_too_large' 值
        // MySQL 不支持直接修改 ENUM，需要先修改为 VARCHAR，然后改回 ENUM
        DB::statement("ALTER TABLE `videos` MODIFY COLUMN `analysis_status` ENUM(
            'pending',
            'metadata_extracting',
            'metadata_extracted',
            'txt_analysis_failed',
            'processing',
            'video_analysis_failed',
            'completed',
            'failed',
            'file_too_large'
        ) DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 移除 'file_too_large' 值
        // 注意：如果已有记录使用 'file_too_large'，需要先更新这些记录
        DB::statement("ALTER TABLE `videos` MODIFY COLUMN `analysis_status` ENUM(
            'pending',
            'metadata_extracting',
            'metadata_extracted',
            'txt_analysis_failed',
            'processing',
            'video_analysis_failed',
            'completed',
            'failed'
        ) DEFAULT 'pending'");
    }
};

