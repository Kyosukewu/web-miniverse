<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| Scheduled Commands
|--------------------------------------------------------------------------
|
| Here you may define all of your scheduled commands. These commands
| will be executed by Laravel's task scheduler.
|
*/

// 排程開關：可通過 .env 中的 SCHEDULER_ENABLED 控制（預設為 false）
$schedulerEnabled = env('SCHEDULER_ENABLED', false);

// 個別任務開關：可獨立控制特定任務是否執行
$analyzeDocumentEnabled = env('ANALYZE_DOCUMENT_ENABLED', true);
$analyzeVideoEnabled = env('ANALYZE_VIDEO_ENABLED', true);
$analyzeFullEnabled = env('ANALYZE_FULL_ENABLED', true);
$cleanupOldVideosEnabled = env('CLEANUP_OLD_VIDEOS_ENABLED', true);

if ($schedulerEnabled) {
    // CNN 資源抓取：每 30 分鐘執行一次（優先執行，為後續分析提供資料）
    Schedule::command('fetch:cnn --group-by=unique-id --keep-local --limit=500 --file-type=all')->everyThirtyMinutes()->onOneServer()->runInBackground();

    // ========== Gemini API 配額限制說明 ==========
    // 根據 https://docs.cloud.google.com/gemini/docs/quotas?hl=zh-tw
    // - 每秒請求數 (RPS): 2 次/秒（每位使用者）
    // - 每日請求數: 960 次/天
    //
    // 優化策略：
    // 1. 降低 limit：從 10 降至 3-5，減少單次執行的請求數
    // 2. 增加間隔：從 10-15 分鐘增至 30-60 分鐘
    // 3. 命令內部添加延迟：確保每秒不超過 2 次請求
    // 4. 每日總請求數控制：~600-700 次/天（留有餘裕）
    // ============================================

    // CNN XML 文檔分析：每 30 分鐘執行一次（依賴 fetch:cnn 的結果）
    // 預計：48 次/天 × 3 個檔案 = 144 次請求/天
    if ($analyzeDocumentEnabled) {
        Schedule::command('analyze:document --source=CNN --storage=gcs --path=cnn --limit=3')->everyThirtyMinutes()->onOneServer()->runInBackground();
    }

    // CNN MP4 影片分析：每 1 小時執行一次（依賴 analyze:document 的結果）
    // 預計：24 次/天 × 5 個影片 = 120 次請求/天
    if ($analyzeVideoEnabled) {
        Schedule::command('analyze:video --source=CNN --storage=gcs --limit=5')->hourly()->onOneServer()->runInBackground();
    }

    // CNN 完整分析：每 1 小時執行一次（建議使用此命令取代上述兩個）
    // 配額計算：
    // - 當前設置：24 次/天 × 10 個影片 = 240 次請求/天
    // - 配額使用率：240 / 960 = 25%（留有 75% 緩衝）
    // - RPS 控制：命令內部已實現 sleep(1)，確保 RPS < 1（遠低於 2 RPS 限制）
    // - 每次執行時間：約 10-15 秒（10 個請求 × 1 秒延遲 + 處理時間）
    // 
    // 如需處理更多，可調整為：
    // - limit=15：360 次/天（37.5% 配額）
    // - limit=20：480 次/天（50% 配額）
    // - limit=30：720 次/天（75% 配額，接近安全上限）
    if ($analyzeFullEnabled) {
        Schedule::command('analyze:full --source=CNN --storage=gcs --limit=30')->hourly()->onOneServer()->runInBackground();
    }

    // 恢復卡住的分析任務：每 10 分鐘檢查一次（超時 1 小時未更新的任務）
    // 恢復卡住的分析任務：每 10 分鐘檢查一次（超時 1 小時未更新的任務）
    // 注意：如果只使用 analyze:full，建議使用 --mode=delete 模式
    // 因為 analyze:full 會跳過所有已存在的記錄，刪除後才能重新處理
    // 如果使用 analyze:document/analyze:video，使用預設的 reset 模式即可
    Schedule::command('analysis:recover --timeout=3600 --mode=delete')
        ->everyTenMinutes()
        ->onOneServer()
        ->withoutOverlapping()
        ->runInBackground();

    // 清理臨時檔案：每小時執行，刪除 2 小時前的臨時檔案
    // 注意：保留時間設為 2 小時，避免與 analyze:full 執行時間衝突
    // analyze:full 每小時執行一次，每次處理約 10 個檔案，每個檔案可能需要幾分鐘
    // 設為 2 小時可確保即使 analyze:full 執行時間較長，也不會刪除正在使用的檔案
    Schedule::call(function () {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            $deletedCount = 0;
            $deletedSize = 0;
            $retentionHours = 2; // 保留 2 小時
            $retentionSeconds = $retentionHours * 3600;
            
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    // 檢查檔案是否正在被使用（通過檔案修改時間）
                    $fileAge = time() - filemtime($file);
                    
                    // 只刪除超過保留時間的檔案
                    if ($fileAge > $retentionSeconds) {
                        // 額外檢查：如果檔案在最近 5 分鐘內被修改，可能是正在使用中，跳過
                        $lastModified = filemtime($file);
                        $recentlyModified = (time() - $lastModified) < 300; // 5 分鐘
                        
                        if (!$recentlyModified) {
                            $size = filesize($file);
                            if (@unlink($file)) {
                                $deletedCount++;
                                $deletedSize += $size;
                            }
                        }
                    }
                }
            }
            
            if ($deletedCount > 0) {
                Log::info('[Scheduler] 清理臨時檔案完成', [
                    'deleted_count' => $deletedCount,
                    'deleted_size_mb' => round($deletedSize / 1024 / 1024, 2),
                    'retention_hours' => $retentionHours,
                ]);
            }
        }
    })->hourlyAt(15)->name('cleanup-temp-files')->onOneServer();

    // 清理過期影片資料（每天凌晨 2 點執行，刪除 14 天前的資料）
    if ($cleanupOldVideosEnabled) {
        Schedule::command('cleanup:old-videos --days=14 --field=analyzed_at --force')
            ->dailyAt('02:00')
            ->onOneServer()
            ->runInBackground();
    }

    // 清理舊日誌檔案（每天凌晨 3 點執行，保留 3 天內的日誌，單檔最大 50MB）
    // 優化：減少保留天數和單檔大小，更積極地清理日誌，防止磁碟空間不足
    Schedule::command('cleanup:logs --days=3 --max-size=50')
        ->dailyAt('03:00')
        ->onOneServer()
        ->runInBackground();
    
    // 緊急清理檢查（每 6 小時執行一次，當磁碟使用率超過 85% 時自動清理）
    // 這是一個預防性措施，在空間不足前就開始清理
    Schedule::call(function () {
        $basePath = storage_path();
        $freeSpace = disk_free_space($basePath);
        $totalSpace = disk_total_space($basePath);
        
        if ($freeSpace !== false && $totalSpace !== false) {
            $usedSpace = $totalSpace - $freeSpace;
            $usagePercent = ($usedSpace / $totalSpace) * 100;
            
            // 如果使用率超過 85%，執行緊急清理
            if ($usagePercent > 85) {
                Log::warning('[Scheduler] 磁碟使用率過高，執行緊急清理', [
                    'usage_percent' => round($usagePercent, 1),
                    'free_space_mb' => round($freeSpace / 1024 / 1024, 2),
                ]);
                
                Artisan::call('cleanup:emergency', [
                    '--force' => true,
                    '--keep-hours' => 0, // 清理所有臨時檔案
                ]);
            }
        }
    })->everySixHours()->name('emergency-cleanup-check')->onOneServer();
}
