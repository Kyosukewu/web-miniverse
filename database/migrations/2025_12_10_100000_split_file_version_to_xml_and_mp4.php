<?php

declare(strict_types=1);

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
        // 步驟 1: 先新增新欄位
        Schema::table('videos', function (Blueprint $table) {
            // 新增 xml_file_version 欄位（整數，儲存版本號，例如 0, 1, 2）
            $table->integer('xml_file_version')
                  ->nullable()
                  ->default(0)
                  ->after('nas_path')
                  ->comment('XML 檔案版本號 (例如: 0, 1, 2)');
            
            // 新增 mp4_file_version 欄位（整數，儲存版本號，例如 0, 1, 2）
            $table->integer('mp4_file_version')
                  ->nullable()
                  ->default(0)
                  ->after('xml_file_version')
                  ->comment('MP4 檔案版本號 (例如: 0, 1, 2)');
        });
        
        // 步驟 2: 遷移現有資料：將舊的 file_version 轉換為數字並設定到兩個欄位
        $videos = DB::table('videos')
            ->whereNotNull('file_version')
            ->select('id', 'file_version')
            ->get();
        
        foreach ($videos as $video) {
            $fileVersion = $video->file_version;
            $versionNumber = 0;
            
            // 提取版本號（例如：_0 -> 0, _1 -> 1）
            if (null !== $fileVersion && preg_match('/^_(\d+)$/', $fileVersion, $matches)) {
                $versionNumber = (int) $matches[1];
            }
            
            DB::table('videos')
                ->where('id', $video->id)
                ->update([
                    'xml_file_version' => $versionNumber,
                    'mp4_file_version' => $versionNumber,
                ]);
        }
        
        // 步驟 3: 移除舊的 file_version 欄位和索引
        Schema::table('videos', function (Blueprint $table) {
            // 移除舊的 file_version 索引
            $table->dropIndex('idx_file_version');
            
            // 移除舊的 file_version 欄位
            $table->dropColumn('file_version');
            
            // 建立新欄位的索引
            $table->index('xml_file_version', 'idx_xml_file_version');
            $table->index('mp4_file_version', 'idx_mp4_file_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // 移除新欄位的索引
            $table->dropIndex('idx_xml_file_version');
            $table->dropIndex('idx_mp4_file_version');
            
            // 移除新欄位
            $table->dropColumn('xml_file_version');
            $table->dropColumn('mp4_file_version');
            
            // 恢復舊的 file_version 欄位
            $table->string('file_version', 10)
                  ->nullable()
                  ->after('nas_path')
                  ->comment('檔案版本號 (例如: _0, _1, _2)');
            $table->index('file_version', 'idx_file_version');
        });
    }
};

