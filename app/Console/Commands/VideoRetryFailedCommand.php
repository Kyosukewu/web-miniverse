<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\VideoRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VideoRetryFailedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:retry-failed
                            {--days=7 : 重試最近 N 天內失敗的影片}
                            {--source= : 指定來源 (CNN, AP, RT)}
                            {--limit= : 限制重試數量}
                            {--force : 強制執行，不需要確認}
                            {--dry-run : 預覽模式，不實際修改資料}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '批量重置失敗的影片分析狀態，使其可以重新分析（用於 API 恢復後）';

    private VideoRepository $videoRepository;

    public function __construct(VideoRepository $videoRepository)
    {
        parent::__construct();
        $this->videoRepository = $videoRepository;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $source = $this->option('source');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info("批量重置失敗影片分析狀態");
        $this->info("=====================================");

        // 構建查詢
        $query = DB::table('videos')
            ->where('analysis_status', AnalysisStatus::VIDEO_ANALYSIS_FAILED->value)
            ->where('analyzed_at', '>=', now()->subDays($days));

        if ($source) {
            $query->where('source_name', $source);
        }

        // 統計資訊
        $totalCount = $query->count();

        if ($totalCount === 0) {
            $this->warn("沒有找到符合條件的失敗影片");
            return Command::SUCCESS;
        }

        // 顯示統計資訊
        $this->info("找到 {$totalCount} 個失敗的影片：");
        $this->info("  - 時間範圍: 最近 {$days} 天");
        if ($source) {
            $this->info("  - 來源: {$source}");
        }
        if ($limit) {
            $this->warn("  - 將只重置前 {$limit} 個影片");
        }

        // 按來源統計
        $bySource = DB::table('videos')
            ->select('source_name', DB::raw('COUNT(*) as count'))
            ->where('analysis_status', AnalysisStatus::VIDEO_ANALYSIS_FAILED->value)
            ->where('analyzed_at', '>=', now()->subDays($days))
            ->when($source, fn ($q) => $q->where('source_name', $source))
            ->groupBy('source_name')
            ->get();

        $this->newLine();
        $this->info("按來源分組：");
        foreach ($bySource as $item) {
            $this->line("  - {$item->source_name}: {$item->count} 個");
        }

        // Dry run 模式
        if ($dryRun) {
            $this->newLine();
            $this->info("🔍 預覽模式：以下影片將被重置");
            $videos = $query->limit($limit ?? 10)->get(['id', 'source_name', 'source_id', 'analyzed_at']);

            $this->table(
                ['ID', '來源', 'Source ID', '失敗時間'],
                $videos->map(fn ($v) => [
                    $v->id,
                    $v->source_name,
                    $v->source_id,
                    $v->analyzed_at,
                ])->toArray()
            );

            if ($totalCount > 10) {
                $this->line("... 還有 " . ($totalCount - 10) . " 個影片未顯示");
            }

            $this->newLine();
            $this->info("✓ 預覽完成。移除 --dry-run 參數以實際執行");
            return Command::SUCCESS;
        }

        // 確認操作
        if (!$force) {
            $this->newLine();
            $this->warn("⚠️  這將重置 " . ($limit ?? $totalCount) . " 個影片的分析狀態");
            $this->warn("   重置後，這些影片將被 analyze:full 重新處理");

            if (!$this->confirm('確定要繼續嗎？', false)) {
                $this->info('操作已取消');
                return Command::FAILURE;
            }
        }

        // 執行重置
        $this->newLine();
        $this->info("開始重置影片狀態...");

        $videoIds = $query->limit($limit)->pluck('id')->toArray();
        $actualCount = count($videoIds);

        $progressBar = $this->output->createProgressBar($actualCount);
        $progressBar->start();

        $successCount = 0;
        $failCount = 0;

        foreach ($videoIds as $videoId) {
            try {
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::PENDING,  // 重置為 PENDING 狀態
                    null  // 清除 analyzed_at
                );
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                $this->newLine();
                $this->error("重置影片 {$videoId} 失敗: " . $e->getMessage());
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // 顯示結果
        $this->info("=====================================");
        $this->info("重置完成！");
        $this->info("  成功: {$successCount} 個");
        if ($failCount > 0) {
            $this->warn("  失敗: {$failCount} 個");
        }

        $this->newLine();
        $this->info("💡 提示：");
        $this->info("  - 這些影片將在下次 analyze:full 執行時被重新處理");
        $this->info("  - analyze:full 每 2 分鐘執行一次，每次處理 1 個影片");
        $this->info("  - 預計需要 " . ceil($successCount * 2) . " 分鐘完成全部重新分析");

        return Command::SUCCESS;
    }
}
