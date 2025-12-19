<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\VideoRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverStuckAnalysisCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analysis:recover
                          {--timeout=3600 : 超時時間（秒），默認 1 小時}
                          {--dry-run : 只顯示會被重置的記錄，不實際修改}
                          {--mode=reset : 處理模式：reset（重置為 METADATA_EXTRACTING）或 delete（刪除記錄，適用於 analyze:full 模式）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '恢復卡住的分析任務（超時未完成的 PROCESSING 狀態）';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private readonly VideoRepository $videoRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $timeout = (int) $this->option('timeout');
        $dryRun = $this->option('dry-run');
        $mode = strtolower($this->option('mode') ?? 'reset');

        // 驗證模式
        if (!in_array($mode, ['reset', 'delete'], true)) {
            $this->error("❌ 無效的模式：{$mode}。請使用 'reset' 或 'delete'");
            return Command::FAILURE;
        }

        $timeoutAgo = now()->subSeconds($timeout);

        $this->info("🔍 查找超過 {$timeout} 秒（" . gmdate('H:i:s', $timeout) . "）未更新的 PROCESSING 狀態影片...");
        $this->info("   基準時間: {$timeoutAgo->format('Y-m-d H:i:s')}");
        $this->newLine();

        // 查找卡住的任務
        $stuckVideos = DB::table('videos')
            ->where('analysis_status', AnalysisStatus::PROCESSING->value)
            ->where('updated_at', '<', $timeoutAgo)
            ->orderBy('updated_at', 'asc')
            ->get();

        if ($stuckVideos->isEmpty()) {
            $this->info("✅ 沒有發現卡住的任務");
            return Command::SUCCESS;
        }

        $this->warn("⚠️  發現 {$stuckVideos->count()} 個卡住的任務：");
        $this->newLine();

        // 準備表格數據
        $table = [];
        foreach ($stuckVideos as $video) {
            $updatedAt = \Carbon\Carbon::parse($video->updated_at);
            $stuckMinutes = now()->diffInMinutes($updatedAt);
            $stuckHours = floor($stuckMinutes / 60);
            $stuckMins = $stuckMinutes % 60;

            $stuckTimeDisplay = $stuckHours > 0
                ? "{$stuckHours} 小時 {$stuckMins} 分鐘前"
                : "{$stuckMins} 分鐘前";

            $table[] = [
                $video->id,
                $video->source_id ?? 'N/A',
                $updatedAt->format('Y-m-d H:i:s'),
                $stuckTimeDisplay,
            ];
        }

        $this->table(
            ['ID', 'Source ID', '最後更新時間', '卡住時間'],
            $table
        );

        if ($dryRun) {
            $this->newLine();
            $this->info("💡 這是 Dry Run 模式，不會實際修改數據");
            $this->info("   移除 --dry-run 參數以執行實際重置");
            return Command::SUCCESS;
        }

        $this->newLine();
        
        // 根據模式顯示不同的確認訊息
        if ('delete' === $mode) {
            $this->warn("⚠️  刪除模式：將刪除這些卡住的記錄，使其可以被 analyze:full 重新處理");
            $confirmMessage = "是否刪除這些卡住的記錄（適用於 analyze:full 模式）？";
        } else {
            $this->info("💡 重置模式：將這些任務重置為 METADATA_EXTRACTING 狀態（適用於 analyze:document/analyze:video 模式）");
            $confirmMessage = "是否將這些任務重置為 METADATA_EXTRACTING 狀態，使其可以重新分析？";
        }
        
        if (!$this->confirm($confirmMessage, true)) {
            $this->info("❌ 已取消操作");
            return Command::SUCCESS;
        }

        // 處理卡住的任務
        $processedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($stuckVideos->count());
        $progressBar->start();

        foreach ($stuckVideos as $video) {
            try {
                if ('delete' === $mode) {
                    // 刪除模式：直接刪除記錄，讓 analyze:full 可以重新處理
                    $this->videoRepository->delete($video->id);
                    $processedCount++;

                    Log::info('[RecoverStuckAnalysis] 刪除卡住的任務', [
                        'video_id' => $video->id,
                        'source_id' => $video->source_id,
                        'stuck_at' => $video->updated_at,
                        'stuck_minutes' => now()->diffInMinutes(\Carbon\Carbon::parse($video->updated_at)),
                        'mode' => 'delete',
                    ]);
                } else {
                    // 重置模式：重置為 METADATA_EXTRACTING（適用於 analyze:document/analyze:video）
                    $this->videoRepository->updateAnalysisStatus(
                        $video->id,
                        AnalysisStatus::METADATA_EXTRACTING,
                        new \DateTime()
                    );
                    $processedCount++;

                    Log::info('[RecoverStuckAnalysis] 重置卡住的任務', [
                        'video_id' => $video->id,
                        'source_id' => $video->source_id,
                        'stuck_at' => $video->updated_at,
                        'stuck_minutes' => now()->diffInMinutes(\Carbon\Carbon::parse($video->updated_at)),
                        'mode' => 'reset',
                    ]);
                }
            } catch (\Exception $e) {
                $errorCount++;
                $this->newLine();
                $action = ('delete' === $mode) ? '刪除' : '重置';
                $this->error("   ✗ {$action} Video ID {$video->id} 失敗: {$e->getMessage()}");

                Log::error('[RecoverStuckAnalysis] 處理任務失敗', [
                    'video_id' => $video->id,
                    'source_id' => $video->source_id,
                    'mode' => $mode,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // 顯示結果摘要
        $actionLabel = ('delete' === $mode) ? '刪除' : '重置';
        $this->info("📊 恢復結果摘要：");
        $this->table(
            ['狀態', '數量'],
            [
                ["成功{$actionLabel}", $processedCount],
                ['失敗', $errorCount],
                ['總計', $stuckVideos->count()],
            ]
        );

        if ($processedCount > 0) {
            $this->newLine();
            if ('delete' === $mode) {
                $this->info("✅ 成功刪除 {$processedCount} 個卡住的任務");
                $this->info("   這些影片將在下次 analyze:full 執行時重新處理（因為記錄已不存在）");
            } else {
                $this->info("✅ 成功重置 {$processedCount} 個卡住的任務");
                $this->info("   這些影片將在下次 analyze:document 或 analyze:video 執行時重新分析");
            }
        }

        if ($errorCount > 0) {
            $this->newLine();
            $this->warn("⚠️  有 {$errorCount} 個任務{$actionLabel}失敗，請檢查日誌");
        }

        return Command::SUCCESS;
    }
}

