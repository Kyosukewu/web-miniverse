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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('source_name', 50);
            $table->string('source_id', 255);
            $table->string('nas_path', 1024);
            $table->string('title', 512)->nullable();
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamp('published_at')->nullable()->comment('發布時間 (文件提供)');
            $table->integer('duration_secs')->nullable()->comment('長度 (秒) (文件提供)');
            $table->text('shotlist_content')->nullable()->comment('SHOTLIST內容 (文件提供)');
            $table->string('view_link', 2048)->nullable()->comment('一鍵看帶連結');
            $table->json('subjects')->nullable()->comment('分類 (來自 TXT 文件分析)');
            $table->string('location', 255)->nullable()->comment('地點 (來自 TXT 文件分析)');
            $table->string('restrictions', 1000)->nullable()->comment('來源 (來自 TXT 文件分析)');
            $table->string('tran_restrictions', 1000)->nullable()->comment('來源翻譯 (來自 TXT 文件分析)');
            $table->string('prompt_version', 50)->nullable()->comment('文本分析使用的 Prompt 版本');
            $table->enum('analysis_status', [
                'pending',
                'metadata_extracting',
                'metadata_extracted',
                'txt_analysis_failed',
                'processing',
                'video_analysis_failed',
                'completed',
                'failed'
            ])->default('pending');
            $table->timestamp('analyzed_at')->nullable();
            $table->json('source_metadata')->nullable();

            // 索引
            $table->unique(['source_name', 'source_id'], 'idx_source');
            $table->index('analysis_status', 'idx_analysis_status');
            $table->index('published_at', 'idx_published_at');
            $table->index(['analysis_status', 'published_at'], 'idx_analysis_status_published_at');
            $table->index('fetched_at', 'idx_fetched_at');
            $table->fullText(['title', 'shotlist_content'], 'idx_title_shotlist');
        });

        // nas_path 前綴索引需要單獨處理（Laravel 不直接支援前綴索引）
        // 使用 DB::statement 來創建前綴索引
        DB::statement('CREATE INDEX idx_nas_path ON videos (nas_path(255))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
