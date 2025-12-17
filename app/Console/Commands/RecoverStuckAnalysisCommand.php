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
                          {--dry-run : 只顯示會被重置的記錄，不實際修改}';

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
        if (!$this->confirm("是否將這些任務重置為 METADATA_EXTRACTING 狀態，使其可以重新分析？", true)) {
            $this->info("❌ 已取消操作");
            return Command::SUCCESS;
        }

        // 重置狀態
        $resetCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($stuckVideos->count());
        $progressBar->start();

        foreach ($stuckVideos as $video) {
            try {
                $this->videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::METADATA_EXTRACTING,
                    new \DateTime()
                );
                $resetCount++;

                Log::info('[RecoverStuckAnalysis] 重置卡住的任務', [
                    'video_id' => $video->id,
                    'source_id' => $video->source_id,
                    'stuck_at' => $video->updated_at,
                    'stuck_minutes' => now()->diffInMinutes(\Carbon\Carbon::parse($video->updated_at)),
                ]);
            } catch (\Exception $e) {
                $errorCount++;
                $this->newLine();
                $this->error("   ✗ 重置 Video ID {$video->id} 失敗: {$e->getMessage()}");

                Log::error('[RecoverStuckAnalysis] 重置任務失敗', [
                    'video_id' => $video->id,
                    'source_id' => $video->source_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // 顯示結果摘要
        $this->info("📊 恢復結果摘要：");
        $this->table(
            ['狀態', '數量'],
            [
                ['成功重置', $resetCount],
                ['失敗', $errorCount],
                ['總計', $stuckVideos->count()],
            ]
        );

        if ($resetCount > 0) {
            $this->newLine();
            $this->info("✅ 成功重置 {$resetCount} 個卡住的任務");
            $this->info("   這些影片將在下次排程執行時重新分析");
        }

        if ($errorCount > 0) {
            $this->newLine();
            $this->warn("⚠️  有 {$errorCount} 個任務重置失敗，請檢查日誌");
        }

        return Command::SUCCESS;
    }
}

