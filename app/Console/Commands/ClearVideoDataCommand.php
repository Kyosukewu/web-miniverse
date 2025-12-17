<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearVideoDataCommand extends Command
{
    /**
     * 控制台命令的名稱和簽名。
     *
     * @var string
     */
    protected $signature = 'video:clear 
                            {--id=* : 指定要刪除的影片 ID（可多個）}
                            {--all : 清空所有影片資料}
                            {--source= : 只清除特定來源的資料（例如：CNN）}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '清除影片和分析結果資料';

    /**
     * 執行控制台命令。
     *
     * @return int
     */
    public function handle(): int
    {
        $ids = $this->option('id');
        $clearAll = $this->option('all');
        $source = $this->option('source');

        // 顯示清空前的資料數量
        $this->info('清空前的資料數量:');
        $this->line('  videos: ' . DB::table('videos')->count());
        $this->line('  analysis_results: ' . DB::table('analysis_results')->count());
        $this->newLine();

        // 驗證參數
        if (!$clearAll && empty($ids) && !$source) {
            $this->error('❌ 請指定清除模式：--all（全部）、--id=N（指定 ID）或 --source=SOURCE（指定來源）');
            return Command::FAILURE;
        }

        if ($clearAll && (!empty($ids) || $source)) {
            $this->error('❌ --all 不能與 --id 或 --source 同時使用');
            return Command::FAILURE;
        }

        // 確認操作
        if ($clearAll) {
            if (!$this->confirm('⚠️  確定要清空所有影片和分析結果嗎？')) {
                $this->info('已取消操作');
                return Command::SUCCESS;
            }
        } elseif ($source) {
            $count = DB::table('videos')->where('source_name', $source)->count();
            if ($count === 0) {
                $this->warn("找不到來源為 {$source} 的資料");
                return Command::SUCCESS;
            }
            if (!$this->confirm("⚠️  確定要刪除來源 {$source} 的 {$count} 筆資料嗎？")) {
                $this->info('已取消操作');
                return Command::SUCCESS;
            }
        } elseif (!empty($ids)) {
            $this->info('將刪除以下 ID 的資料: ' . implode(', ', $ids));
            if (!$this->confirm('⚠️  確定要刪除這些資料嗎？')) {
                $this->info('已取消操作');
                return Command::SUCCESS;
            }
        }

        try {
            // 禁用外鍵檢查
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $this->line('🔓 已禁用外鍵檢查');

            if ($clearAll) {
                // 清空全部
                DB::table('analysis_results')->truncate();
                $this->info('✅ analysis_results 資料表已清空');

                DB::table('videos')->truncate();
                $this->info('✅ videos 資料表已清空');
            } elseif ($source) {
                // 清除特定來源
                $videoIds = DB::table('videos')
                    ->where('source_name', $source)
                    ->pluck('id');

                $analysisDeleted = DB::table('analysis_results')
                    ->whereIn('video_id', $videoIds)
                    ->delete();
                $this->info("✅ 已刪除 {$analysisDeleted} 筆 analysis_results");

                $videosDeleted = DB::table('videos')
                    ->where('source_name', $source)
                    ->delete();
                $this->info("✅ 已刪除 {$videosDeleted} 筆 videos");
            } else {
                // 刪除指定 ID
                $validIds = [];
                foreach ($ids as $id) {
                    $video = DB::table('videos')->where('id', $id)->first();
                    if ($video) {
                        $validIds[] = $id;
                    } else {
                        $this->warn("⚠️  影片 ID {$id} 不存在，跳過");
                    }
                }

                if (!empty($validIds)) {
                    // 先刪除 analysis_results
                    $analysisDeleted = DB::table('analysis_results')
                        ->whereIn('video_id', $validIds)
                        ->delete();
                    $this->info("✅ 已刪除 {$analysisDeleted} 筆 analysis_results");

                    // 再刪除 videos
                    $videosDeleted = DB::table('videos')
                        ->whereIn('id', $validIds)
                        ->delete();
                    $this->info("✅ 已刪除 {$videosDeleted} 筆 videos");
                }
            }

            // 重新啟用外鍵檢查
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->line('🔒 已重新啟用外鍵檢查');

            $this->newLine();
            $this->info('清空後的資料數量:');
            $this->line('  videos: ' . DB::table('videos')->count());
            $this->line('  analysis_results: ' . DB::table('analysis_results')->count());

            return Command::SUCCESS;
        } catch (\Exception $e) {
            // 確保重新啟用外鍵檢查
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            $this->error('❌ 清除資料失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

