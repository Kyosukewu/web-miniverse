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

    // CNN XML 文檔分析：每 10 分鐘執行一次（依賴 fetch:cnn 的結果）
    if ($analyzeDocumentEnabled) {
        Schedule::command('analyze:document --source=CNN --storage=gcs --path=cnn --limit=10')->everyTenMinutes()->onOneServer()->runInBackground();
    }

    // CNN MP4 影片分析：每 15 分鐘執行一次（依賴 analyze:document 的結果）
    if ($analyzeVideoEnabled) {
        Schedule::command('analyze:video --source=CNN --storage=gcs --limit=10')->everyFifteenMinutes()->onOneServer()->runInBackground();
    }

    // CNN 完整分析：每 15 分鐘執行一次
    if ($analyzeFullEnabled) {
        Schedule::command('analyze:full --source=CNN --storage=gcs --limit=10')->everyFifteenMinutes()->onOneServer()->runInBackground();
    }

    // 恢復卡住的分析任務：每 10 分鐘檢查一次（超時 1 小時未更新的任務）
    Schedule::command('analysis:recover --timeout=3600')
        ->everyTenMinutes()
        ->onOneServer()
        ->withoutOverlapping()
        ->runInBackground();

    // 清理臨時檔案：每小時執行，刪除 1 小時前的臨時檔案
    Schedule::call(function () {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            $deletedCount = 0;
            $deletedSize = 0;
            
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && (time() - filemtime($file)) > 3600) { // 1 hour
                    $size = filesize($file);
                    if (@unlink($file)) {
                        $deletedCount++;
                        $deletedSize += $size;
                    }
                }
            }
            
            if ($deletedCount > 0) {
                Log::info('[Scheduler] 清理臨時檔案完成', [
                    'deleted_count' => $deletedCount,
                    'deleted_size_mb' => round($deletedSize / 1024 / 1024, 2),
                ]);
            }
        }
    })->hourly()->name('cleanup-temp-files')->onOneServer();

    // 清理過期影片資料（每天凌晨 2 點執行，刪除 14 天前的資料）
    if ($cleanupOldVideosEnabled) {
        Schedule::command('cleanup:old-videos --days=14 --field=analyzed_at --force')
            ->dailyAt('02:00')
            ->onOneServer()
            ->runInBackground();
    }
}
