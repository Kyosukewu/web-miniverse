<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CleanupOldVideosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:old-videos 
                            {--days=14 : 刪除多少天前的資料（預設 14 天）}
                            {--field=analyzed_at : 使用哪個欄位判斷（analyzed_at, fetched_at, 或 both）}
                            {--dry-run : 僅顯示將要刪除的記錄，不實際刪除}
                            {--force : 跳過確認直接執行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '刪除過期的影片資料（根據 analyzed_at 或 fetched_at）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $field = $this->option('field');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info("開始清理 {$days} 天前的影片資料...");
        $this->info("使用欄位: {$field}");

        // 計算截止日期
        $cutoffDate = Carbon::now()->subDays($days);

        // 根據選擇的欄位查詢要刪除的記錄
        $query = Video::query();

        if ('both' === $field) {
            // 使用 analyzed_at 或 fetched_at（取較晚的）
            $query->where(function ($q) use ($cutoffDate) {
                $q->where(function ($subQ) use ($cutoffDate) {
                    // analyzed_at 存在且小於截止日期
                    $subQ->whereNotNull('analyzed_at')
                         ->where('analyzed_at', '<', $cutoffDate);
                })->orWhere(function ($subQ) use ($cutoffDate) {
                    // analyzed_at 為空，使用 fetched_at
                    $subQ->whereNull('analyzed_at')
                         ->where('fetched_at', '<', $cutoffDate);
                });
            });
        } elseif ('fetched_at' === $field) {
            $query->where('fetched_at', '<', $cutoffDate);
        } else {
            // 預設使用 analyzed_at，如果為空則使用 fetched_at
            $query->where(function ($q) use ($cutoffDate) {
                $q->where(function ($subQ) use ($cutoffDate) {
                    $subQ->whereNotNull('analyzed_at')
                         ->where('analyzed_at', '<', $cutoffDate);
                })->orWhere(function ($subQ) use ($cutoffDate) {
                    $subQ->whereNull('analyzed_at')
                         ->where('fetched_at', '<', $cutoffDate);
                });
            });
        }

        // 統計要刪除的記錄數
        $count = $query->count();

        if (0 === $count) {
            $this->info("沒有找到需要刪除的記錄。");
            return Command::SUCCESS;
        }

        $this->warn("找到 {$count} 筆記錄將被刪除（截止日期: {$cutoffDate->format('Y-m-d H:i:s')}）");

        // 顯示前 10 筆記錄（預覽）
        $preview = $query->limit(10)->get(['id', 'source_id', 'title', 'analyzed_at', 'fetched_at']);
        if ($preview->isNotEmpty()) {
            $this->info("\n預覽（前 10 筆）:");
            $this->table(
                ['ID', 'Source ID', 'Title', 'Analyzed At', 'Fetched At'],
                $preview->map(function ($video) {
                    return [
                        $video->id,
                        $video->source_id,
                        Str::limit($video->title ?? 'N/A', 30),
                        $video->analyzed_at?->format('Y-m-d H:i:s') ?? 'N/A',
                        $video->fetched_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    ];
                })->toArray()
            );
        }

        // 如果是 dry-run，只顯示不刪除
        if ($dryRun) {
            $this->info("\n[DRY RUN] 不會實際刪除記錄。");
            $this->info("如果要實際刪除，請移除 --dry-run 選項。");
            return Command::SUCCESS;
        }

        // 確認刪除
        if (!$force) {
            if (!$this->confirm("確定要刪除這 {$count} 筆記錄嗎？", false)) {
                $this->info("已取消刪除。");
                return Command::SUCCESS;
            }
        }

        // 執行刪除（使用事務確保資料一致性）
        $this->info("\n開始刪除...");

        try {
            DB::transaction(function () use ($query, $count) {
                // 先刪除關聯的 analysis_results
                $videoIds = $query->pluck('id')->toArray();
                
                if (!empty($videoIds)) {
                    $deletedAnalysisResults = DB::table('analysis_results')
                        ->whereIn('video_id', $videoIds)
                        ->delete();
                    
                    Log::info('[CleanupOldVideos] 刪除關聯的分析結果', [
                        'count' => $deletedAnalysisResults,
                    ]);
                }

                // 刪除 videos
                $deletedVideos = $query->delete();

                Log::info('[CleanupOldVideos] 刪除過期影片', [
                    'deleted_count' => $deletedVideos,
                    'total_count' => $count,
                ]);
            });

            $this->info("✅ 成功刪除 {$count} 筆記錄。");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ 刪除失敗: " . $e->getMessage());
            Log::error('[CleanupOldVideos] 刪除失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

