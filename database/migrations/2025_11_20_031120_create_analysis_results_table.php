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
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->unsignedBigInteger('video_id')->primary();
            $table->text('transcript')->nullable();
            $table->text('translation')->nullable();
            $table->text('short_summary')->nullable()->comment('短摘要 (Gemini分析)');
            $table->text('bulleted_summary')->nullable()->comment('列點摘要 (Gemini分析)');
            $table->json('bites')->nullable()->comment('BITE (講者：「說了什麼」) (Gemini分析)');
            $table->json('mentioned_locations')->nullable()->comment('地點 (文稿內) (Gemini分析)');
            $table->json('importance_score')->nullable()->comment('重要性評分 (Gemini分析)');
            $table->string('material_type', 100)->nullable()->comment('素材類型 (Gemini分析)');
            $table->json('related_news')->nullable()->comment('相關新聞 (Gemini分析)');
            $table->text('visual_description')->nullable();
            $table->json('topics')->nullable();
            $table->json('keywords')->nullable();
            $table->text('error_message')->nullable();
            $table->string('prompt_version', 50)->nullable()->comment('用於分析的 Prompt 版本');
            $table->timestamps();

            $table->foreign('video_id')
                  ->references('id')
                  ->on('videos')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};
