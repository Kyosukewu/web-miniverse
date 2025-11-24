<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AnalyzeVideoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analyze:video 
                            {--source= : 來源名稱 (CNN, AP, RT 等，可選，用於過濾)}
                            {--storage=s3 : 儲存空間類型 (nas, s3, storage)}
                            {--limit=50 : 每次處理的影片數量上限}
                            {--prompt-version= : Prompt 版本 (可選)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '從資料庫查詢未完成分析的影片並進行分析';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private AnalyzeService $analyzeService,
        private StorageService $storageService,
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
        $sourceName = $this->option('source') ? strtoupper($this->option('source')) : null;
        $storageType = strtolower($this->option('storage'));
        $limit = (int) $this->option('limit');
        $promptVersion = $this->option('prompt-version');

        $sourceFilter = $sourceName ? "來源: {$sourceName}, " : '';
        $this->info("開始查詢未完成分析的影片 ({$sourceFilter}儲存空間: {$storageType})");

        // Query incomplete videos from database
        $videos = $this->videoRepository->getIncompleteVideos($sourceName, $limit);

        if ($videos->isEmpty()) {
            $this->warn("未找到需要分析的影片");
            return Command::SUCCESS;
        }

        $this->info("找到 " . $videos->count() . " 個需要分析的影片");

        // Analyze videos
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($videos->count());
        $progressBar->start();

        foreach ($videos as $video) {
            if ($processedCount >= $limit) {
                break;
            }

            // Skip if nas_path is empty
            if (empty($video->nas_path)) {
                $this->warn("\n跳過缺少 nas_path 的影片: {$video->source_id}");
                $skippedCount++;
                $progressBar->advance();
                continue;
            }

            try {
                // Update status to processing
                $this->videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::PROCESSING,
                    new \DateTime()
                );

                // Get video file path from nas_path
                $videoFilePath = $this->storageService->getVideoFilePath($storageType, $video->nas_path);

                if (null === $videoFilePath) {
                    throw new \Exception("無法取得影片檔案路徑: {$video->nas_path}");
                }

                // For local storage types, check if file exists
                if (in_array($storageType, ['nas', 'local', 'storage'], true)) {
                    if (!file_exists($videoFilePath)) {
                        throw new \Exception("影片檔案不存在: {$videoFilePath} (nas_path: {$video->nas_path})");
                    }
                }

                // Execute video analysis
                $analysisResult = $this->analyzeService->executeVideoAnalysis(
                    $video->id,
                    $promptVersion,
                    $videoFilePath
                );

                // Save analysis result
                $this->analyzeService->saveAnalysisResult(
                    $video->id,
                    $analysisResult,
                    $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v6'
                );

                $this->line("\n✓ 完成分析: {$video->source_id} ({$video->nas_path})");
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[AnalyzeVideoCommand] 分析影片失敗', [
                    'video_id' => $video->id,
                    'source_id' => $video->source_id,
                    'nas_path' => $video->nas_path,
                    'error' => $e->getMessage(),
                ]);

                // Update status to failed
                $this->videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );

                $this->error("\n✗ 分析失敗: {$video->source_id} - {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info("分析完成！");
        $this->table(
            ['狀態', '數量'],
            [
                ['已處理', $processedCount],
                ['已跳過', $skippedCount],
                ['錯誤', $errorCount],
            ]
        );

        return Command::SUCCESS;
    }
}
