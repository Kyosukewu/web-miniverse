<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\SyncStatus;
use App\Models\Video;
use App\Repositories\VideoRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 一次性命令：更新現有資料的 sync_status
 * 
 * 此命令用於在新增 sync_status 欄位後，為現有的 videos 記錄補上對應的狀態資訊。
 * 
 * 更新邏輯：
 * 1. 如果 analysis_status = 'completed' 且有 analysis_result → sync_status = 'parsed'
 * 2. 如果 analysis_status = 'completed' 但沒有 analysis_result → sync_status = 'parsed'（已分析完成）
 * 3. 如果 analysis_status != 'completed' 但 nas_path 存在 → sync_status = 'synced'（已同步到 GCS）
 * 4. 如果 nas_path 不存在或為空 → sync_status = 'updated'（可能需要重新同步）
 */
class UpdateSyncStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:update-status
                            {--source= : 指定來源名稱（可選，例如：CNN）}
                            {--dry-run : 乾跑模式，只顯示會更新的記錄，不實際更新}
                            {--batch-size=1000 : 每批處理的記錄數量（預設 1000）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '一次性命令：更新現有 videos 記錄的 sync_status 狀態';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private VideoRepository $videoRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $sourceName = $this->option('source');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：只顯示會更新的記錄，不實際更新資料庫');
        }

        $this->info('開始更新 sync_status 狀態...');

        // 建立查詢
        $query = Video::query();

        // 如果指定了來源，只處理該來源的記錄
        if (null !== $sourceName && '' !== $sourceName) {
            $query->where('source_name', strtoupper($sourceName));
            $this->info("📊 只處理來源: {$sourceName}");
        }

        // 只處理 sync_status 為 null 或空字串的記錄（尚未設定狀態的記錄）
        $query->where(function ($q) {
            $q->whereNull('sync_status')
              ->orWhere('sync_status', '');
        });

        $totalCount = $query->count();

        if (0 === $totalCount) {
            $this->info('✓ 沒有需要更新的記錄（所有記錄都已設定 sync_status）');
            return Command::SUCCESS;
        }

        $this->info("找到 {$totalCount} 筆需要更新的記錄");

        // 統計變數
        $parsedCount = 0;
        $syncedCount = 0;
        $updatedCount = 0;
        $errorCount = 0;
        $processedCount = 0;

        // 建立進度條
        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        // 使用批次處理避免記憶體問題
        $query->chunk($batchSize, function ($videos) use (&$parsedCount, &$syncedCount, &$updatedCount, &$errorCount, &$processedCount, $dryRun, $progressBar) {
            foreach ($videos as $video) {
                try {
                    $newStatus = $this->determineSyncStatus($video);

                    if (null === $newStatus) {
                        // 無法確定狀態，跳過
                        $progressBar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        // 乾跑模式：只顯示前 10 筆詳細資訊，避免輸出過多
                        if ($processedCount < 10) {
                            $this->line("\n  [乾跑] Video ID: {$video->id}, Source: {$video->source_name}, Source ID: {$video->source_id}, 將設定為: {$newStatus->value}");
                        }
                    } else {
                        // 更新 sync_status
                        $this->videoRepository->update($video->id, [
                            'sync_status' => $newStatus->value,
                        ]);
                    }

                    // 統計
                    match ($newStatus) {
                        SyncStatus::PARSED => $parsedCount++,
                        SyncStatus::SYNCED => $syncedCount++,
                        SyncStatus::UPDATED => $updatedCount++,
                    };

                    $processedCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error('[UpdateSyncStatusCommand] 更新記錄失敗', [
                        'video_id' => $video->id,
                        'source_id' => $video->source_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $this->error("\n  ✗ 更新失敗: Video ID {$video->id} - {$e->getMessage()}");
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();

        // 顯示結果
        $this->newLine();
        $this->info('✅ 更新完成！');
        $this->table(
            ['狀態', '數量'],
            [
                ['parsed（已解析）', $parsedCount],
                ['synced（已同步）', $syncedCount],
                ['updated（更新）', $updatedCount],
                ['錯誤', $errorCount],
                ['總計', $parsedCount + $syncedCount + $updatedCount],
            ]
        );

        if ($dryRun) {
            $this->warn('⚠️  這是乾跑模式，資料庫未實際更新。請移除 --dry-run 選項以執行實際更新。');
        }

        return Command::SUCCESS;
    }

    /**
     * 根據 video 記錄的狀態決定 sync_status
     *
     * @param Video $video
     * @return SyncStatus|null
     */
    /**
     * 根據 video 記錄的狀態決定 sync_status
     * 
     * 優先順序：
     * 1. 如果 analysis_status = 'completed' → 'parsed'（已解析完成，最高優先級）
     * 2. 如果 nas_path 存在且不為空 → 'synced'（已同步到 GCS）
     * 3. 如果 nas_path 不存在或為空 → 'updated'（可能需要重新同步）
     *
     * @param Video $video
     * @return SyncStatus|null
     */
    private function determineSyncStatus(Video $video): ?SyncStatus
    {
        // 規則 1: 如果 analysis_status = 'completed'，設為 'parsed'（最高優先級）
        // 這表示已經完成分析，無論是否有 analysis_result 記錄
        if ($video->analysis_status === AnalysisStatus::COMPLETED) {
            return SyncStatus::PARSED;
        }

        // 規則 2: 如果 nas_path 存在且不為空，設為 'synced'（已同步到 GCS）
        // 這表示檔案已經上傳到 GCS，但尚未完成分析
        if (null !== $video->nas_path && '' !== trim($video->nas_path)) {
            return SyncStatus::SYNCED;
        }

        // 規則 3: 如果 nas_path 不存在或為空，設為 'updated'（可能需要重新同步）
        // 這表示記錄存在但檔案可能尚未同步到 GCS
        return SyncStatus::UPDATED;
    }
}

